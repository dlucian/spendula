<?php

namespace App\Services\Counterparty;

use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;

/**
 * SPEC §6.8 counterparty ladder.
 *
 * - L0: direction-correct debtor/creditor name (universal)
 * - L1: direction-inverted debtor/creditor name (Mock ASPSP, some RO banks)
 * - L2: remittance_information[0] processed by per-bank rule engine
 * - L3: additional_information, falling back to bank_transaction_code.description
 * - L4: "(Unknown)"
 *
 * L0/L1/L3/L4 are universal and stay in code. L2 delegates to the
 * RuleEngine, which loads bank-specific rules from
 * config/counterparty-rules-enabled/<bank>.json (managed by the
 * spendula:counterparty:rules:* commands). Pass null bank slug for
 * transactions whose bank is unknown — no rules will apply, the
 * trimmed remittance is returned.
 */
class Resolver
{
    public function __construct(
        private readonly RuleLoader $ruleLoader,
        private readonly RuleEngine $ruleEngine,
    ) {}

    /**
     * Resolve a counterparty name + level for an Enable Banking transaction.
     *
     * Success: returns ResolvedCounterparty with the lowest-numbered ladder
     *   level that produced a non-empty result. L0 = direction-correct
     *   debtor/creditor name; L1 = direction-inverted (Mock ASPSP, some RO
     *   banks); L2 = first terminal match from the bank's rule engine over
     *   remittance_information[0] (or the trimmed remittance if no rule
     *   matches); L3 = additional_information when non-empty, else
     *   bank_transaction_code.description when non-empty; L4 = "(Unknown)".
     *
     * Failure: throws RuleValidationException (from RuleLoader::forBank())
     *   when bankSlug is non-null and the enabled rule file for that bank
     *   fails validation. Caller does not catch; the exception propagates
     *   to abort the surrounding command. This is by design — invalid
     *   rules should not silently degrade resolution for an entire bank.
     *
     * Side effects: file read via RuleLoader on first call per bank slug
     *   per process (cached thereafter). No DB access. No network. No
     *   logging.
     *
     * Idempotency: safe to call repeatedly with the same arguments;
     *   identical inputs always produce identical outputs.
     *
     * Concurrency: no advisory lock required. Pure computation given the
     *   loader's cached rule list. Two parallel callers in the same process
     *   share the loader cache.
     *
     * @param  array<string, mixed>  $transaction  EB transaction array
     *                                             (typically from $payload->raw_payload). Read keys:
     *                                             credit_debit_indicator, creditor.name, debtor.name,
     *                                             remittance_information[0], additional_information,
     *                                             bank_transaction_code.description.
     * @param  ?string  $bankSlug  Bank slug to scope rules to. null means
     *                             no rules apply (L2 returns the trimmed remittance verbatim).
     */
    public function resolve(array $transaction, ?string $bankSlug = null): ResolvedCounterparty
    {
        $cdi = isset($transaction['credit_debit_indicator']) && is_string($transaction['credit_debit_indicator'])
            ? strtoupper($transaction['credit_debit_indicator'])
            : '';

        $creditor = $this->extractName($transaction, 'creditor');
        $debtor = $this->extractName($transaction, 'debtor');

        // Level 0: direction-correct.
        $directCorrect = match ($cdi) {
            'CRDT' => $debtor,
            'DBIT' => $creditor,
            default => null,
        };
        if (is_string($directCorrect) && $directCorrect !== '') {
            return new ResolvedCounterparty($directCorrect, 0);
        }

        // Level 1: direction-inverted (Mock ASPSP + some RO banks).
        $inverted = match ($cdi) {
            'CRDT' => $creditor,
            'DBIT' => $debtor,
            default => null,
        };
        if (is_string($inverted) && $inverted !== '') {
            return new ResolvedCounterparty($inverted, 1);
        }

        // Level 2: rule engine over remittance_information[0].
        if (isset($transaction['remittance_information']) && is_array($transaction['remittance_information'])) {
            $first = $transaction['remittance_information'][0] ?? null;
            if (is_string($first) && trim($first) !== '') {
                $rules = $bankSlug !== null ? $this->ruleLoader->forBank($bankSlug) : [];
                $extracted = $this->ruleEngine->apply($first, $rules);
                if ($extracted !== '') {
                    return new ResolvedCounterparty(mb_substr($extracted, 0, 64), 2);
                }
            }
        }

        // Level 3: additional_information, falling back to bank_transaction_code.description.
        if (isset($transaction['additional_information']) && is_string($transaction['additional_information'])) {
            $trimmed = trim($transaction['additional_information']);
            if ($trimmed !== '') {
                return new ResolvedCounterparty(mb_substr($trimmed, 0, 64), 3);
            }
        }

        if (isset($transaction['bank_transaction_code']) && is_array($transaction['bank_transaction_code'])) {
            $description = $transaction['bank_transaction_code']['description'] ?? null;
            if (is_string($description)) {
                $trimmed = trim($description);
                if ($trimmed !== '') {
                    return new ResolvedCounterparty(mb_substr($trimmed, 0, 64), 3);
                }
            }
        }

        // Level 4: unknown.
        return new ResolvedCounterparty('(Unknown)', 4);
    }

    /**
     * Normalized counterparty used for dedup fundamentals (SPEC §6.3):
     * lowercased, whitespace-collapsed, non-alphanumerics stripped.
     * Empty string is valid — matches §6.3 "if empty, use empty string".
     */
    public static function normalize(?string $counterparty): string
    {
        if ($counterparty === null || $counterparty === '') {
            return '';
        }

        $lower = mb_strtolower($counterparty);
        // Unicode-aware: \p{L}\p{N} preserves diacritics and non-Latin scripts.
        $noAlphanum = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/', ' ', $noAlphanum) ?? '');
    }

    /** @param  array<string, mixed>  $transaction */
    private function extractName(array $transaction, string $party): ?string
    {
        $node = $transaction[$party] ?? null;
        if (! is_array($node)) {
            return null;
        }
        $name = $node['name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        return null;
    }
}
