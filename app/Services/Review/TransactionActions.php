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
     * Inverse of approve()/skip()/markTransfer() — used by the review CLI
     * undo flow (SPEC §7.1, GH #20). Sets `status = fetched` and clears
     * skip metadata so the row re-enters the review queue exactly as it
     * arrived from sync. Idempotent on a row already at `fetched`.
     *
     * Out of scope: rows mass-approved via `bulkApproveTrivial` are not
     * tracked on the in-memory undo stack (they never went through an
     * interactive decision), so this method is not reachable for them
     * from the review loop. It is still safe to call directly.
     */
    public function revertToFetched(Transaction $transaction): Transaction
    {
        $transaction->status = TransactionStatus::Fetched;
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

    /**
     * spendula:review --approve-all (GH #22): approve every remaining `fetched`
     * row, regardless of resolution level or currency. Returns the count of rows
     * transitioned.
     *
     * Success: only rows still at status=fetched are affected; they become
     *   status=approved with skip metadata cleared, and are then eligible for
     *   spendula:push (which pushes approved + transfer). Rows already in any
     *   other status — approved, skipped, transfer, transfer_dropped, pushed,
     *   tracking — are untouched. Callers that also apply PayeeRuleEngine should
     *   do so BEFORE calling this, so operator-authored skip/transfer rules
     *   remove their rows from the fetched pool first and win precedence.
     *
     * Side effects: single bulk UPDATE on `transactions`. No HTTP, no events.
     *
     * Idempotency: safe to re-run — a second call finds no fetched rows and
     *   returns 0.
     *
     * Concurrency: intended to run under AdvisoryLock::REVIEW (held by the
     *   review command), mirroring bulkApproveTrivial.
     */
    public function bulkApproveAll(): int
    {
        return Transaction::query()
            ->where('status', TransactionStatus::Fetched->value)
            ->update([
                'status' => TransactionStatus::Approved->value,
                'skipped_at' => null,
                'skip_reason' => null,
                'updated_at' => Carbon::now(),
            ]);
    }
}
