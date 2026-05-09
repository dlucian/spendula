<?php

namespace App\Services\Counterparty;

use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use App\Services\Counterparty\Rules\RuleOutcome;

/**
 * SPEC §6.8 counterparty ladder.
 *
 * - **ATM short-circuit** (GH #42): DBIT + `bank_transaction_code.code = "ATM"`
 *       returns the configured synthetic label at level 1 before any
 *       L0/L1 name lookup. Universal; runs for every bank.
 * - L0: direction-correct debtor/creditor name (universal), passed
 *       through the bank's `name_rules` if any matched
 * - L1: direction-inverted debtor/creditor name (Mock ASPSP, some RO
 *       banks), also passed through `name_rules`
 * - L2: remittance_information[0] processed by per-bank `rules`
 * - L3: additional_information, falling back to bank_transaction_code.description
 * - L4: "(Unknown)"
 *
 * L0/L1/L3/L4 are universal and stay in code. L0/L1 cleanup and L2
 * extraction both delegate to the RuleEngine but consume different
 * per-bank lists: `name_rules` for L0/L1 (operating on the resolved
 * creditor/debtor name) and `rules` for L2 (operating on
 * remittance_information[0]). Both lists live in
 * config/counterparty-rules-enabled/<bank>.json (managed by the
 * spendula:counterparty:rules:* commands). Pass null bank slug for
 * transactions whose bank is unknown — no rules will apply, the raw
 * L0/L1 name or trimmed remittance is returned. Name-rule rewrites
 * keep the originating level (0 or 1); the rewrite is cleanup, not
 * a level transition.
 */
class Resolver
{
    private readonly string $atmCashLabel;

    public function __construct(
        private readonly RuleLoader $ruleLoader,
        private readonly RuleEngine $ruleEngine,
        string $atmCashLabel = 'ATM Cash',
    ) {
        // Defensive: an empty / whitespace-only label would land every ATM
        // withdrawal at counterparty_name = '' (or whitespace), which the
        // dedup hasher then normalises to ''. Treat blank as "use the
        // default" rather than honouring the empty value (codex review
        // round 2 — covers callers that bypass the config-side `?:`
        // fallback by passing the label directly). Trim before storing so
        // accidental whitespace in SPENDULA_ATM_CASH_LABEL (e.g.
        // `" Cash withdrawal "`) does not leak into counterparty_name and
        // perturb dedup / payee-rule matching (Copilot review PR #44).
        $trimmed = trim($atmCashLabel);
        $this->atmCashLabel = $trimmed !== '' ? $trimmed : 'ATM Cash';
    }

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

        // Name-rule list is loaded once per resolve() call: L0 and L1
        // share it, and at most one of them produces a return. With null
        // bank slug we skip rule consultation entirely so callers with
        // unknown bank get current pre-name-rule behaviour.
        //
        // Loaded BEFORE the GH #42 ATM short-circuit so that a malformed
        // rule file still throws RuleValidationException on every
        // bank-scoped call — the resolver's documented fail-fast behaviour
        // must not depend on which transaction shape happens to be
        // resolved first (codex review round 2 P2).
        $nameRules = $bankSlug !== null ? $this->ruleLoader->nameRulesForBank($bankSlug) : [];

        // GH #42 — ATM short-circuit. ISO 20022 `bank_transaction_code.code = "ATM"`
        // marks a cash-withdrawal event. On DBIT the SEPA-correct counterparty is
        // the debtor (cardholder = operator's own name), which is useless as a
        // YNAB payee — every withdrawal would resolve under the operator's legal
        // name and collide with self-transfers. Replace it with a configurable
        // synthetic label at level 1 before any name lookup runs. CRDT (cash
        // deposit at ATM) and non-ATM transaction codes fall through to the
        // normal ladder.
        if ($cdi === 'DBIT' && $this->bankTransactionCode($transaction) === 'ATM') {
            return new ResolvedCounterparty(mb_substr($this->atmCashLabel, 0, 64), 1);
        }

        $creditor = $this->extractName($transaction, 'creditor');
        $debtor = $this->extractName($transaction, 'debtor');

        // Level 0: direction-correct.
        $directCorrect = match ($cdi) {
            'CRDT' => $debtor,
            'DBIT' => $creditor,
            default => null,
        };
        if (is_string($directCorrect) && $directCorrect !== '') {
            $outcome = $nameRules !== []
                ? $this->ruleEngine->resolveOutcome($directCorrect, $nameRules)
                : RuleOutcome::unmatched();

            if ($outcome->result !== null) {
                // Rule fired with a non-empty rewrite: truncate to 64
                // (matching the L2/L3 contract for cleaned outputs).
                return new ResolvedCounterparty(mb_substr($outcome->result, 0, 64), 0);
            }
            if (! $outcome->matched) {
                // No rule fired: preserve the raw L0 name verbatim — no
                // implicit trim, no truncation. (Whitespace and >64 char
                // names round-trip unchanged for clean callers.)
                return new ResolvedCounterparty($directCorrect, 0);
            }
            // matched && result === null: a rule intentionally blanked
            // the L0 candidate. Fall through to L1 so the operator's
            // suppressive rule redirects resolution down the ladder.
        }

        // Level 1: direction-inverted (Mock ASPSP + some RO banks).
        $inverted = match ($cdi) {
            'CRDT' => $creditor,
            'DBIT' => $debtor,
            default => null,
        };
        if (is_string($inverted) && $inverted !== '') {
            $outcome = $nameRules !== []
                ? $this->ruleEngine->resolveOutcome($inverted, $nameRules)
                : RuleOutcome::unmatched();

            if ($outcome->result !== null) {
                return new ResolvedCounterparty(mb_substr($outcome->result, 0, 64), 1);
            }
            if (! $outcome->matched) {
                return new ResolvedCounterparty($inverted, 1);
            }
            // matched && blanked: fall through to L2.
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

    /**
     * Read the upper-cased `bank_transaction_code.code` from the raw
     * transaction envelope, or null when the field is missing, the
     * parent is not an array, or the value is not a string. Used by
     * the ATM short-circuit (GH #42).
     *
     * @param  array<string, mixed>  $transaction
     */
    private function bankTransactionCode(array $transaction): ?string
    {
        $node = $transaction['bank_transaction_code'] ?? null;
        if (! is_array($node)) {
            return null;
        }
        $code = $node['code'] ?? null;
        if (! is_string($code)) {
            return null;
        }

        return strtoupper($code);
    }
}
