<?php

declare(strict_types=1);

namespace App\Services\Counterparty;

use App\Models\BankAccount;

/**
 * DB-aware post-resolution classifier for own-account transfers and FX moves.
 *
 * Runs AFTER the Resolver produces a counterparty name; the Resolver itself is
 * contractually pure / no-DB and cannot perform the IBAN→own-account lookup.
 * This classifier does that lookup and returns an OwnAccountClassification when
 * the destination IBAN matches exactly one active own account (excluding the
 * source account itself). Callers use the classification to override the
 * counterparty name and, for same-currency transfers, force status=transfer.
 *
 * IBAN lookup is direction-aware:
 *   DBIT → check `creditor_account.iban` first, then free-text "To account,"
 *   CRDT → check `debtor_account.iban` first, then free-text "From account,"
 *
 * Duplicate-IBAN guard: `bank_accounts.iban` is nullable and NOT unique. When
 * multiple active accounts share the same normalized IBAN the classification
 * is ambiguous — returns null (no override) rather than silently picking one.
 *
 * @see OwnAccountClassification
 */
final class OwnAccountClassifier
{
    /**
     * Normalized-IBAN → list-of-BankAccount map. Null until first classify() call.
     * Cached for the lifetime of the instance so repeated calls per sync row
     * (potentially hundreds per page) do not re-query the DB.
     *
     * @var array<string, list<BankAccount>>|null
     */
    private ?array $ibanMap = null;

    /**
     * Classify a raw EB transaction as an own-account transfer or FX move.
     *
     * Success: returns OwnAccountClassification when the destination IBAN
     *   resolves to exactly ONE active account (other than $source). The
     *   `sameCurrency` flag drives the caller's status / name decision:
     *   true → same-currency transfer; false → cross-currency FX move.
     *
     * Failure: returns null — not an own-account transaction, or ambiguous.
     *   Null cases:
     *     - No IBAN found in the transaction envelope (structured or free-text).
     *     - The IBAN does not match any active account (external transaction).
     *     - The only match is the source account itself (self-transfer — not possible in practice
     *       but rejected for safety).
     *     - Multiple active accounts share the same normalized IBAN (ambiguous; no override).
     *     - The IBAN matches only an inactive account.
     *
     * Side effects: on first call, issues SELECT on `bank_accounts` to build an
     *   in-memory normalized-IBAN map; all subsequent calls hit only the cache.
     *   No writes. No network.
     *
     * Idempotency: safe to call repeatedly with the same arguments.
     *
     * Concurrency: no advisory lock required. The IBAN map is built once per
     *   instance (per process/command run) and is read-only thereafter. A
     *   concurrent account de/activation during a long sync run is accepted;
     *   the next run produces a fresh instance.
     *
     * @param  array<string, mixed>  $ebTransaction  Raw EB transaction envelope.
     * @param  BankAccount  $source  The account being synced.
     */
    public function classify(array $ebTransaction, BankAccount $source): ?OwnAccountClassification
    {
        $map = $this->loadIbanMap();
        $iban = $this->extractDestinationIban($ebTransaction, $map);
        if ($iban === null) {
            return null;
        }

        $normalized = self::normalizeIban($iban);
        if ($normalized === '') {
            return null;
        }
        $candidates = $map[$normalized] ?? [];

        // Ambiguity guard: require exactly one active account for this IBAN
        // BEFORE source exclusion. If two active accounts share the same
        // normalized IBAN the destination is indeterminate — no override even
        // when one of the matches is the source itself.
        if (count($candidates) !== 1) {
            return null;
        }

        $destination = $candidates[0];

        // Self-transfer prevention: the sole candidate is the source itself.
        if ($destination->id === $source->id) {
            return null;
        }

        $txCurrencyRaw = $ebTransaction['transaction_amount']['currency'] ?? null;
        $txCurrency = strtoupper(is_string($txCurrencyRaw) ? $txCurrencyRaw : $source->currency);
        $sameCurrency = $txCurrency === strtoupper($destination->currency);

        return new OwnAccountClassification(
            destination: $destination,
            destinationIban: $normalized,
            sameCurrency: $sameCurrency,
        );
    }

    /**
     * Normalize an IBAN to a canonical form: stripped whitespace, upper-cased.
     * Used both when building the lookup map and when normalizing a candidate
     * extracted from the transaction envelope — ensures consistent matching.
     */
    public static function normalizeIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    /**
     * Extract the destination-account IBAN from the EB transaction envelope.
     *
     * Direction-aware: for a DBIT the money goes to the creditor; for a CRDT
     * it comes from the debtor. Try the structured account field first; fall
     * back to free-text patterns in `remittance_information[]`.
     *
     * Free-text fallback strategy: after locating the "To account," / "From
     * account," tag, the remainder of the line is normalized (strip whitespace,
     * uppercase) and each known own-account IBAN is tested as a prefix of that
     * normalized text. Exactly one prefix hit → return that IBAN; zero or
     * more-than-one → null. This avoids brittle IBAN-length guessing and
     * naturally ignores trailing words (e.g. "RO00 BANK … 0040 Details" →
     * normalized → prefix-matched → own IBAN) whether or not a comma separator
     * is present after the IBAN.
     *
     * Returns null when no IBAN is found or the direction is unrecognised.
     *
     * @param  array<string, mixed>  $ebTransaction
     * @param  array<string, list<BankAccount>>  $ibanMap  Normalized-IBAN → accounts map (used for free-text prefix lookup).
     */
    private function extractDestinationIban(array $ebTransaction, array $ibanMap): ?string
    {
        $cdi = strtoupper(
            is_string($ebTransaction['credit_debit_indicator'] ?? null)
                ? $ebTransaction['credit_debit_indicator']
                : ''
        );

        // Structured field (present when EB populates it; null for many ING-RO rows).
        if ($cdi === 'DBIT') {
            $structured = ($ebTransaction['creditor_account'] ?? null);
        } elseif ($cdi === 'CRDT') {
            $structured = ($ebTransaction['debtor_account'] ?? null);
        } else {
            return null;
        }

        if (is_array($structured) && is_string($structured['iban'] ?? null) && trim($structured['iban']) !== '') {
            return $structured['iban'];
        }

        // Free-text fallback: direction-aware tag search in remittance_information[].
        // DBIT: "To account, <IBAN...>" (ING-RO business format)
        // CRDT: "From account, <IBAN...>" (mirror of the same format on the inbound side)
        //
        // Everything after the tag is normalized (whitespace stripped, uppercased)
        // and prefix-matched against the known own-account IBAN set. One match →
        // own-account hit. Zero or multiple → no classification for this line.
        $tagPattern = $cdi === 'CRDT'
            ? '/\bFrom account,\s*(.*)/i'
            : '/\bTo account,\s*(.*)/i';

        $remittances = $ebTransaction['remittance_information'] ?? null;
        if (! is_array($remittances)) {
            return null;
        }

        foreach ($remittances as $line) {
            if (! is_string($line)) {
                continue;
            }
            if (preg_match($tagPattern, $line, $m) !== 1) {
                continue;
            }

            $normalizedRemainder = self::normalizeIban($m[1]);
            if ($normalizedRemainder === '') {
                continue;
            }

            $hits = [];
            foreach ($ibanMap as $normalizedIban => $_) {
                if (str_starts_with($normalizedRemainder, $normalizedIban)) {
                    $hits[] = $normalizedIban;
                }
            }

            if (count($hits) === 1) {
                return $hits[0];
            }
        }

        return null;
    }

    /**
     * Load the normalized-IBAN → BankAccount map from the DB, caching on the instance.
     *
     * Only active accounts with a non-null IBAN are included. Accounts sharing a
     * normalized IBAN are grouped as a list; the caller checks count === 1 before
     * trusting the match.
     *
     * @return array<string, list<BankAccount>>
     */
    private function loadIbanMap(): array
    {
        if ($this->ibanMap !== null) {
            return $this->ibanMap;
        }

        $this->ibanMap = [];

        /** @var iterable<BankAccount> $accounts */
        $accounts = BankAccount::query()->where('active', true)->whereNotNull('iban')->get();

        foreach ($accounts as $account) {
            $normalized = self::normalizeIban((string) $account->iban);
            if ($normalized === '') {
                continue;
            }
            $this->ibanMap[$normalized][] = $account;
        }

        return $this->ibanMap;
    }
}
