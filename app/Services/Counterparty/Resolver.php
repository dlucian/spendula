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
        // BCP direct debit (handled with trailing-reference cut by
        // extractFromDdWithReference — falls through to this prefix only
        // when the merchant has no numeric reference, e.g. "DD ESSENTIA").
        '/^DD\s+/i',
        // BCP service payment:
        '/^PAGSERV\s+/i',
        // BCP toll/parking via Via Verde tag: "PAG BXVAL- NNNN VIAVERDE".
        '/^PAG\s+BXVAL-\s+\d+\s+/i',
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
     * BCP direct-debit shape: "DD <merchant> <ref-digits> [<alpha-token>]
     * (PT|DI)<id>" where:
     *
     *   - the merchant capture is non-digit (`[^\d]+?`) so descriptors
     *     that embed numbers themselves (e.g. "DD ACME 2024 PLAN PT…",
     *     "DD GYM 1234 PREMIUM 000123 PT…") fall through.
     *   - the customer reference must be 8+ digits to prove it's a
     *     real reference rather than a year/plan code (a 4-digit token
     *     directly before the creditor id is structurally
     *     indistinguishable from a merchant whose name ends with a
     *     year, e.g. "DD AMAZON 2024 PT…").
     *   - an optional one-word alpha sub-product token (e.g. "MEDIS"
     *     in "DD OCIDENTAL 00346849108 MEDIS DI…") is allowed between
     *     the reference and the creditor id; numeric intermediate
     *     tokens are rejected (could be year/plan codes too).
     *
     * Falling through is strictly safer: over-merging distinct payees
     * by mis-cutting at an embedded number costs more than leaving a
     * noisy payee. SUNSETFITGYM (4-digit ref in real BCP data) is
     * accepted as collateral; its 2 rows aren't worth the false-
     * positive risk against arbitrary year-suffixed merchants.
     */
    private const string BCP_DD_WITH_REFERENCE_PATTERN =
        '/^DD\s+(?P<merchant>[^\d]+?)\s+\d{8,}(?:\s+\p{L}+)?\s+(?:PT|DI)\d{6,}\s*$/iu';

    /**
     * BCP ATM withdrawal: "LEV ATM <card4> <atm-id>   <location>        <cardholder>".
     * Cardholder is BCP echoing the account holder back — drop it.
     * Location capture uses a lazy `.+?` terminated by 4+ whitespace —
     * the cardholder gap in observed BCP data is ≥8 spaces while internal
     * location spacing (e.g. multi-word place names with stray padding)
     * stays at 1-2 spaces. Internal whitespace runs in the captured
     * location are collapsed to single spaces by extractFromLevAtm().
     */
    private const string BCP_LEV_ATM_PATTERN =
        '/^LEV\s+ATM\s+\d+\s+\d+\s+(?P<location>.+?)\s{4,}\S/i';

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
        // Tried in order: BCP-specific shape detectors (LEV ATM, DD-with-
        // reference) first because their cleanup is destructive and
        // shape-aware; then structured-CSV extraction (ING RO et al.);
        // then generic prefix + suffix stripping (BCP COMPRA / TRF / etc).
        if (isset($transaction['remittance_information']) && is_array($transaction['remittance_information'])) {
            $first = $transaction['remittance_information'][0] ?? null;
            if (is_string($first) && trim($first) !== '') {
                $extracted = $this->extractFromLevAtm($first)
                    ?? $this->extractFromDdWithReference($first)
                    ?? $this->extractFromStructured($first)
                    ?? $this->stripPrefixes($first);
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

    /**
     * Collapse a BCP direct-debit line to its merchant name only, dropping
     * the customer reference + creditor identifiers. Returns null if the
     * input is not a DD line, or doesn't contain a numeric reference (in
     * which case generic DD prefix stripping handles it).
     */
    private function extractFromDdWithReference(string $text): ?string
    {
        if (preg_match(self::BCP_DD_WITH_REFERENCE_PATTERN, $text, $m) !== 1) {
            return null;
        }
        // Strip trailing punctuation (BCP's "EDP COMERCIAL-" hyphen artifact).
        $merchant = rtrim(trim($m['merchant']), " \t\n\r\0\x0B-_.,;:");

        return $merchant !== '' ? $merchant : null;
    }

    /**
     * Normalise BCP ATM withdrawals to "ATM <location>" so every cash
     * withdrawal in a city aggregates onto one YNAB payee. Falls back to
     * the bare "ATM" label when the line starts with "LEV ATM" but the
     * shape doesn't match the location-extraction pattern. Returns null
     * for non-ATM lines so the caller continues with normal prefix/suffix
     * stripping.
     */
    private function extractFromLevAtm(string $text): ?string
    {
        if (preg_match('/^LEV\s+ATM\b/i', $text) !== 1) {
            return null;
        }
        if (preg_match(self::BCP_LEV_ATM_PATTERN, $text, $m) === 1) {
            // Collapse internal whitespace runs so "VILA  NOVA" becomes
            // "VILA NOVA" — same physical place, one YNAB payee.
            $location = trim((string) preg_replace('/\s+/', ' ', $m['location']));
            if ($location !== '') {
                return 'ATM '.$location;
            }
        }

        return 'ATM';
    }
}
