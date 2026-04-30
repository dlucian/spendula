<?php

namespace App\Services\Counterparty;

/**
 * SPEC §6.8 counterparty ladder. Pure function over an EB transaction array;
 * no DB access, no side effects, so the sync path stays testable in isolation.
 */
class Resolver
{
    /**
     * Banking prefixes that pollute remittance_information[0]. Stripped
     * greedily (first match wins). Order matters — longer / more specific
     * patterns first so "CARD PAYMENT " doesn't get trimmed to just
     * "CARD ", and so "TRF MB WAY P  " doesn't get trimmed to just "TRF ".
     *
     * Each entry is a regex anchored at the start (case-insensitive). The
     * static prefixes use literal matches; the BCP-specific COMPRA/TRF
     * entries handle Portuguese banking patterns observed in real
     * production data (4-digit card-number prefix, transfer-to-person
     * variants, etc.).
     */
    private const array REMITTANCE_PREFIX_PATTERNS = [
        // Generic English (existing):
        '/^CARD PAYMENT\s+/i',
        '/^POS PURCHASE\s+/i',
        '/^PURCHASE\s+/i',
        '/^SEPA DD\s+/i',
        '/^SEPA CT\s+/i',
        '/^POS\s+/i',
        // BCP card-purchase prefix: `COMPRA NNNN ` where NNNN is the last 4
        // of the card or a merchant-category code (observed: 9800, 5962).
        '/^COMPRA\s+\d{3,5}\s+/i',
        // BCP transfer-to-person variants:
        //   "TRF DE <name>"            (TRANSFER FROM)
        //   "TRF MB WAY P  <name>"     (MB WAY = Portuguese mobile pay; double space observed)
        //   "TRF P  <name>", "TRF P <name>"
        //   "TRF. P O <name>", "TRF. P  <name>"
        '/^TRF\.?\s+(DE|MB\s+WAY\s+P|P\s+O|P)\s+/i',
        // BCP direct debit:
        '/^DD\s+/i',
        // BCP service payment:
        '/^PAGSERV\s+/i',
    ];

    /**
     * Suffixes appended by some banks to card-purchase remittance lines
     * (BCP especially). Stripped after prefix removal. Conservative —
     * only strings that appear consistently in observed data, never
     * a bare 2-letter country code (too risky to mangle merchant names).
     */
    private const array REMITTANCE_SUFFIX_PATTERNS = [
        '/\s+CONTACTLESS\s*$/i',
    ];

    /**
     * ING RO Business and similar banks return a structured CSV-like
     * remittance:
     *   "Card number, **** XXXX, Transaction at, MERCHANT, Authorization date, …"
     * Extract the merchant directly so we don't ship the whole metadata
     * blob into YNAB as a payee name.
     */
    private const string ING_STRUCTURED_PATTERN =
        '/^Card number,\s*\*+\s*\d+,\s*Transaction at,\s*(?P<merchant>.+?)(?=,\s*Authorization date,|$)/iu';

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

        // Level 2: extract a clean counterparty from remittance_information[0].
        // Tried in order: structured-CSV extraction (ING RO et al.) first,
        // then prefix + suffix stripping (BCP, generic patterns).
        if (isset($transaction['remittance_information']) && is_array($transaction['remittance_information'])) {
            $first = $transaction['remittance_information'][0] ?? null;
            if (is_string($first) && trim($first) !== '') {
                $extracted = $this->extractFromStructured($first) ?? $this->stripPrefixes($first);
                $extracted = $this->stripSuffixes($extracted);
                if ($extracted !== '') {
                    return new ResolvedCounterparty(mb_substr($extracted, 0, 64), 2);
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
        foreach (self::REMITTANCE_PREFIX_PATTERNS as $pattern) {
            $stripped = preg_replace($pattern, '', $text, 1);
            if (is_string($stripped) && $stripped !== $text) {
                return trim($stripped);
            }
        }

        return trim($text);
    }

    private function stripSuffixes(string $text): string
    {
        foreach (self::REMITTANCE_SUFFIX_PATTERNS as $pattern) {
            $stripped = preg_replace($pattern, '', $text, 1);
            if (is_string($stripped) && $stripped !== $text) {
                $text = $stripped;
            }
        }

        return trim($text);
    }

    /**
     * Pull the merchant out of an ING RO Business "Card number, …,
     * Transaction at, MERCHANT, Authorization date, …" line. Returns
     * null if the pattern doesn't match or the merchant capture is
     * empty.
     */
    private function extractFromStructured(string $text): ?string
    {
        if (preg_match(self::ING_STRUCTURED_PATTERN, $text, $m) === 1) {
            $merchant = trim($m['merchant']);

            return $merchant !== '' ? $merchant : null;
        }

        return null;
    }
}
