<?php

namespace App\Services\Review;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Pure DB-facing transitions used by the review CLI (SPEC §7.1). Extracted
 * from the TTY-heavy ReviewSession so the state machine itself is testable
 * without raw-mode stdin shenanigans.
 *
 * Each method is idempotent against the target status — calling approve()
 * on an already-approved row is a no-op update of timestamps.
 */
class TransactionActions
{
    public function approve(Transaction $transaction): Transaction
    {
        $transaction->status = TransactionStatus::Approved;
        $transaction->skipped_at = null;
        $transaction->skip_reason = null;
        $transaction->save();

        return $transaction;
    }

    public function skip(Transaction $transaction, ?string $reason = null): Transaction
    {
        $transaction->status = TransactionStatus::Skipped;
        $transaction->skipped_at = Carbon::now();
        $transaction->skip_reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : null;
        $transaction->save();

        return $transaction;
    }

    public function markTransfer(Transaction $transaction): Transaction
    {
        $transaction->status = TransactionStatus::Transfer;
        $transaction->skipped_at = null;
        $transaction->skip_reason = null;
        $transaction->save();

        return $transaction;
    }

    /**
     * SPEC §7.1 --bulk-approve-trivial: auto-approve every `fetched` row where
     * resolution level ≤ 1 AND currency is the base currency. Returns the count
     * of rows transitioned. Intended for operators who've validated their bank's
     * counterparty reporting and want to skim confidence.
     */
    public function bulkApproveTrivial(string $baseCurrency): int
    {
        return Transaction::query()
            ->where('status', TransactionStatus::Fetched->value)
            ->where('counterparty_resolution_level', '<=', 1)
            ->where('currency', strtoupper($baseCurrency))
            ->update([
                'status' => TransactionStatus::Approved->value,
                'skipped_at' => null,
                'skip_reason' => null,
                'updated_at' => Carbon::now(),
            ]);
    }
}
