<?php

namespace App\Services\Status;

use Illuminate\Support\Carbon;

/**
 * Pure data carrier for the spendula:status dashboard.
 *
 * StatusSnapshotBuilder produces this; StatusRenderer consumes it. No DB
 * access on either side of the boundary — the snapshot is the single
 * source of truth for the rendered output AND the exit code.
 *
 * The snapshot is captured inside a single REPEATABLE READ READ ONLY
 * transaction (see StatusSnapshotBuilder) so the four reads below are
 * mutually consistent against concurrent sync/push/auth-callback writes.
 *
 * @immutable
 */
final class StatusSnapshot
{
    /**
     * @param  list<BankRow>  $banks
     * @param  list<StuckTransactionRow>  $stuckTransactions
     * @param  list<RecentErrorRow>  $recentErrors
     */
    public function __construct(
        public readonly array $banks,
        public readonly array $stuckTransactions,
        public readonly bool $hasRedOrStuckRows,
        public readonly bool $isEmpty,
        public readonly Carbon $generatedAt,
        public readonly array $recentErrors = [],
    ) {}

    public function hasRedOrStuckRows(): bool
    {
        return $this->hasRedOrStuckRows;
    }
}
