<?php

namespace App\Services\Sync;

use App\Services\Counterparty\Resolver;

/**
 * dedup_hash (SPEC §6.7) and import_id (SPEC §7.3) are both stability primitives:
 *
 * - dedup_hash is used for traceability and as a cheap fallback index. The
 *   authoritative match key is (bank_account_id, dedup_hash, occurrence).
 *
 * - import_id is the 36-char identity YNAB uses to dedup on the server side.
 *   Structurally similar but includes `occurrence` so same-day/same-amount
 *   legitimate duplicates get different IDs.
 *
 * Both are pure functions of their inputs — no wall time involved — so re-runs
 * against the same transaction produce identical hashes.
 */
class DedupHasher
{
    /**
     * SPEC §6.7 dedup hash input order:
     *   bank_account_id | booking_date | amount_milliunits | currency |
     *   credit_debit_indicator | normalized_counterparty | entry_reference
     */
    public static function dedupHash(
        string $bankAccountId,
        string $bookingDate,
        int $amountMilliunits,
        string $currency,
        string $creditDebitIndicator,
        ?string $rawCounterparty,
        ?string $entryReference,
    ): string {
        $input = implode('|', [
            $bankAccountId,
            $bookingDate,
            (string) $amountMilliunits,
            strtoupper($currency),
            strtoupper($creditDebitIndicator),
            Resolver::normalize($rawCounterparty),
            $entryReference ?? '',
        ]);

        return substr(hash('sha256', $input), 0, 32);
    }

    /**
     * SPEC §7.3 import_id = "SPNDL:" + substr(sha1(…), 0, 30) — exactly 36 chars.
     * occurrence is what separates legitimate same-day duplicates: two identical
     * coffees get occurrence=1 and occurrence=2 and thus different import_ids.
     */
    public static function importId(
        string $bankAccountId,
        string $bookingDate,
        int $amountMilliunits,
        ?string $rawCounterparty,
        int $occurrence,
    ): string {
        $input = implode('|', [
            $bankAccountId,
            $bookingDate,
            (string) $amountMilliunits,
            Resolver::normalize($rawCounterparty),
            (string) $occurrence,
        ]);

        return 'SPNDL:'.substr(sha1($input), 0, 30);
    }
}
