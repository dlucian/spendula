<?php

namespace App\Services\Status;

use Illuminate\Support\Carbon;

/**
 * One row in the spendula:status "Recent errors" panel.
 *
 * Captures the small intersection of `sync_run_errors` and `push_run_errors`
 * that the dashboard surfaces:
 *
 *   - When did it happen (`created_at`)
 *   - Which run produced it (`runKind` = sync|push, `runId`)
 *   - HTTP status if upstream reported one
 *   - Bank + account context if the error was tied to one (sync errors may
 *     have `bank_account_id = null` for connection-scoped failures; push
 *     errors are always tied to a transaction → bank_account → bank)
 *   - The persisted `error_detail` string (already includes the EB/YNAB
 *     response body when ErrorDetailFormatter had one to append)
 *
 * Truncation for display is the renderer's job — the DTO carries the full
 * persisted detail string so the snapshot remains the single source of
 * truth.
 *
 * @immutable
 */
final class RecentErrorRow
{
    public function __construct(
        public readonly Carbon $createdAt,
        /** 'sync' or 'push'. */
        public readonly string $runKind,
        public readonly int $runId,
        public readonly ?int $httpStatus,
        public readonly ?string $bankDisplayName,
        public readonly ?string $bankAccountDisplayName,
        public readonly string $detail,
    ) {}
}
