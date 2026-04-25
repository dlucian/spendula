<?php

namespace App\Services\Counterparty;

/**
 * SPEC §6.8 counterparty ladder. Pure function over an EB transaction array;
 * no DB access, no side effects, so the sync path stays testable in isolation.
 */
class Resolver
{
    /**
     * Banking prefixes that pollute remittance_information[0] on many RO banks
     * and on some Western European banks. Stripped greedily (first match wins).
     * Order matters — longer prefixes first so "CARD PAYMENT " doesn't get
     * trimmed to just "CARD ".
     */
    private const array REMITTANCE_PREFIXES = [
        'CARD PAYMENT ',
        'POS PURCHASE ',
        'PURCHASE ',
        'SEPA DD ',
        'SEPA CT ',
        'POS ',
    ];

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function resolve(array $transaction): ResolvedCounterparty
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

        // Level 2: remittance_information[0] stripped of common banking prefixes, truncated to 64.
        if (isset($transaction['remittance_information']) && is_array($transaction['remittance_information'])) {
            $first = $transaction['remittance_information'][0] ?? null;
            if (is_string($first) && trim($first) !== '') {
                $stripped = $this->stripPrefixes($first);
                if ($stripped !== '') {
                    return new ResolvedCounterparty(mb_substr($stripped, 0, 64), 2);
                }
            }
        }

        // Level 3: additional_information.
        if (isset($transaction['additional_information']) && is_string($transaction['additional_information'])) {
            $trimmed = trim($transaction['additional_information']);
            if ($trimmed !== '') {
                return new ResolvedCounterparty(mb_substr($trimmed, 0, 64), 3);
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
        // The previous /[^a-z0-9]+/ stripped them, so "Bäckerei" became "b ckerei"
        // and Cyrillic / CJK names collapsed to '', breaking dedup matching.
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

    private function stripPrefixes(string $text): string
    {
        $upper = strtoupper($text);
        foreach (self::REMITTANCE_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return trim(substr($text, strlen($prefix)));
            }
        }

        return trim($text);
    }
}
