<?php

namespace App\Services\Status;

use App\Enums\BankConnectionStatus;
use App\Enums\TransactionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the snapshot consumed by spendula:status.
 *
 * Success: returns a StatusSnapshot reflecting (a) per-bank consent state on
 *   the selected (active-or-most-recent) connection, (b) per-bank queued
 *   transaction counts (fetched/approved/transfer/tracking only — pushed
 *   and skipped are deliberately excluded), (c) per-bank wall-times for
 *   sync/push/snapshot, (d) push-stuck transactions whose
 *   push_attempt_count >= PUSH_STUCK_ATTEMPTS AND status IN ('approved',
 *   'transfer') AND ynab_transaction_id IS NULL.
 *
 * Failure: no bespoke exceptions; surfaces any DB error from the wrapping
 *   transaction.
 *
 * Side effects: a single read-only Postgres transaction. No writes.
 *
 * Idempotency: trivially idempotent (read-only).
 *
 * Concurrency: opens REPEATABLE READ READ ONLY so the four reads share a
 *   single MVCC snapshot — concurrent sync/push/auth-callback writers
 *   cannot tear the dashboard's view across queries.
 */
class StatusSnapshotBuilder
{
    /**
     * @param  ?Carbon  $now  Inject a fixed clock for deterministic tests; null uses Carbon::now().
     */
    public function build(bool $includeMock, ?Carbon $now = null): StatusSnapshot
    {
        $generatedAt = $now ?? Carbon::now();

        // SET TRANSACTION ISOLATION LEVEL must be the first statement in
        // a fresh transaction. If we're already nested inside an outer
        // transaction (RefreshDatabase wraps each test in one) Postgres
        // rejects the statement; in that case we degrade to the outer
        // isolation level — read-only safety on a single-operator DB at
        // dashboard scale is preserved by the absence of writes here.
        $alreadyInTx = DB::transactionLevel() > 0;

        /** @var StatusSnapshot $snapshot */
        $snapshot = DB::transaction(function () use ($includeMock, $generatedAt, $alreadyInTx): StatusSnapshot {
            if (! $alreadyInTx) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            $banks = $this->loadBanks($includeMock, $generatedAt);
            $stuck = $this->loadStuckTransactions($includeMock);
            $recentErrors = $this->loadRecentErrors($includeMock, $generatedAt);

            return $this->assemble($banks, $stuck, $recentErrors, $generatedAt);
        });

        return $snapshot;
    }

    /**
     * @return list<BankRow>
     */
    private function loadBanks(bool $includeMock, Carbon $generatedAt): array
    {
        $banksQuery = DB::table('banks')
            ->where('active', true)
            ->orderBy('slug');

        if (! $includeMock) {
            $banksQuery->where('slug', '!=', 'mock');
        }

        $bankRows = $banksQuery->get(['slug', 'display_name', 'active']);

        if ($bankRows->isEmpty()) {
            return [];
        }

        /** @var list<string> $slugs */
        $slugs = array_values($bankRows->pluck('slug')->map(fn ($s) => (string) $s)->all());

        // Per-bank: pick the active connection if present, else the most
        // recent connection by created_at. Captured once, used for both
        // consent state and last_synced_at.
        $connections = $this->loadSelectedConnections($slugs);

        // Queued counts grouped by bank+status; zero-fill missing rows in PHP.
        $queuedCounts = $this->loadQueuedCounts($slugs);

        // Wall-times for push and snapshot (sync wall-time comes from the
        // selected connection itself).
        $lastPushedAt = $this->loadLastPushedAt($slugs);
        $lastSnapshotAt = $this->loadLastSnapshotAt($slugs);

        $rows = [];
        foreach ($bankRows as $b) {
            $slug = (string) $b->slug;
            $displayName = (string) $b->display_name;
            $bankActive = (bool) $b->active;

            $conn = $connections[$slug] ?? null;
            [$consentStatus, $effectiveStatus, $validUntil, $daysRemaining, $warningLevel] =
                $this->computeConsentFields($conn, $generatedAt);

            $rawLastSyncedAt = is_object($conn) && property_exists($conn, 'last_synced_at')
                ? $conn->last_synced_at
                : null;
            $lastSyncedAt = $rawLastSyncedAt !== null
                ? Carbon::parse((string) $rawLastSyncedAt)
                : null;

            $counts = $queuedCounts[$slug] ?? [];
            $queuedCountsByStatus = [
                'fetched' => (int) ($counts[TransactionStatus::Fetched->value] ?? 0),
                'approved' => (int) ($counts[TransactionStatus::Approved->value] ?? 0),
                'transfer' => (int) ($counts[TransactionStatus::Transfer->value] ?? 0),
                'tracking' => (int) ($counts[TransactionStatus::Tracking->value] ?? 0),
            ];

            // Stale-sync warning is gated: only matters for an active bank
            // whose effective consent is active. Other states already
            // signal red elsewhere (consent), so a second warning would be
            // noise.
            $syncStale = $bankActive
                && $effectiveStatus === BankConnectionStatus::Active->value
                && $this->isStale($lastSyncedAt, $generatedAt);

            $rows[] = new BankRow(
                slug: $slug,
                displayName: $displayName,
                bankActive: $bankActive,
                consentValidUntil: $validUntil,
                consentDaysRemaining: $daysRemaining,
                consentStatus: $consentStatus,
                effectiveConsentStatus: $effectiveStatus,
                consentWarningLevel: $warningLevel,
                queuedCounts: $queuedCountsByStatus,
                lastSyncedAt: $lastSyncedAt,
                lastPushedAt: $lastPushedAt[$slug] ?? null,
                lastSnapshotAt: $lastSnapshotAt[$slug] ?? null,
                syncStale: $syncStale,
            );
        }

        return $rows;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, object>
     */
    private function loadSelectedConnections(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        // Per bank: the active row if present, else the most recent
        // (by created_at desc, then id desc as tiebreaker). Postgres
        // DISTINCT ON achieves this in one query.
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $sql = "SELECT DISTINCT ON (bank_slug)
                bank_slug, status, valid_until, last_synced_at
             FROM bank_connections
             WHERE bank_slug IN ({$placeholders})
             ORDER BY bank_slug, (status = 'active') DESC, created_at DESC, id DESC";

        $rows = DB::select($sql, $slugs);

        $byBank = [];
        foreach ($rows as $row) {
            $byBank[(string) $row->bank_slug] = $row;
        }

        return $byBank;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, array<string, int>>
     */
    private function loadQueuedCounts(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $statuses = [
            TransactionStatus::Fetched->value,
            TransactionStatus::Approved->value,
            TransactionStatus::Transfer->value,
            TransactionStatus::Tracking->value,
        ];

        $rows = DB::table('transactions')
            ->join('bank_accounts', 'transactions.bank_account_id', '=', 'bank_accounts.id')
            ->whereIn('bank_accounts.bank_slug', $slugs)
            ->whereIn('transactions.status', $statuses)
            ->groupBy('bank_accounts.bank_slug', 'transactions.status')
            ->select([
                'bank_accounts.bank_slug as bank_slug',
                'transactions.status as status',
                DB::raw('COUNT(*) as cnt'),
            ])
            ->get();

        $byBank = [];
        foreach ($rows as $r) {
            $byBank[(string) $r->bank_slug][(string) $r->status] = (int) $r->cnt;
        }

        return $byBank;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, Carbon>
     */
    private function loadLastPushedAt(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $rows = DB::table('transactions')
            ->join('bank_accounts', 'transactions.bank_account_id', '=', 'bank_accounts.id')
            ->whereIn('bank_accounts.bank_slug', $slugs)
            ->whereNotNull('transactions.pushed_at')
            ->groupBy('bank_accounts.bank_slug')
            ->select([
                'bank_accounts.bank_slug as bank_slug',
                DB::raw('MAX(transactions.pushed_at) as last_pushed_at'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->bank_slug] = Carbon::parse((string) $r->last_pushed_at);
        }

        return $out;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, Carbon>
     */
    private function loadLastSnapshotAt(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $rows = DB::table('tracking_snapshots')
            ->join('bank_accounts', 'tracking_snapshots.bank_account_id', '=', 'bank_accounts.id')
            ->whereIn('bank_accounts.bank_slug', $slugs)
            ->groupBy('bank_accounts.bank_slug')
            ->select([
                'bank_accounts.bank_slug as bank_slug',
                DB::raw('MAX(tracking_snapshots.pushed_at) as last_snapshot_at'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->bank_slug] = Carbon::parse((string) $r->last_snapshot_at);
        }

        return $out;
    }

    /**
     * @return list<StuckTransactionRow>
     */
    private function loadStuckTransactions(bool $includeMock): array
    {
        $query = DB::table('transactions')
            ->join('bank_accounts', 'transactions.bank_account_id', '=', 'bank_accounts.id')
            ->join('banks', 'bank_accounts.bank_slug', '=', 'banks.slug')
            ->where('banks.active', true)
            ->where('transactions.push_attempt_count', '>=', Thresholds::PUSH_STUCK_ATTEMPTS)
            ->whereIn('transactions.status', [
                TransactionStatus::Approved->value,
                TransactionStatus::Transfer->value,
            ])
            ->whereNull('transactions.ynab_transaction_id');

        if (! $includeMock) {
            $query->where('banks.slug', '!=', 'mock');
        }

        $rows = $query
            ->orderByDesc('transactions.push_attempt_count')
            ->orderByDesc('transactions.last_push_attempt_at')
            ->select([
                'transactions.id as transaction_id',
                'transactions.booking_date as booking_date',
                'transactions.amount_milliunits as amount_milliunits',
                'transactions.currency as currency',
                'transactions.counterparty_name as counterparty_name',
                'transactions.push_attempt_count as push_attempt_count',
                'transactions.last_push_attempt_at as last_push_attempt_at',
                'transactions.last_push_error as last_push_error',
                'banks.display_name as bank_display_name',
                'bank_accounts.display_name as bank_account_display_name',
                'bank_accounts.iban as bank_account_iban',
                'bank_accounts.id as bank_account_id',
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $accountLabel = (string) ($r->bank_account_display_name
                ?? $r->bank_account_iban
                ?? $r->bank_account_id);

            $out[] = new StuckTransactionRow(
                bankDisplayName: (string) $r->bank_display_name,
                bankAccountDisplayName: $accountLabel,
                transactionId: (string) $r->transaction_id,
                bookingDate: Carbon::parse((string) $r->booking_date),
                amountMilliunits: (int) $r->amount_milliunits,
                currency: (string) $r->currency,
                counterpartyName: $r->counterparty_name !== null ? (string) $r->counterparty_name : null,
                pushAttemptCount: (int) $r->push_attempt_count,
                lastPushAttemptAt: $r->last_push_attempt_at !== null
                    ? Carbon::parse((string) $r->last_push_attempt_at)
                    : null,
                lastPushError: $r->last_push_error !== null ? (string) $r->last_push_error : null,
            );
        }

        return $out;
    }

    /**
     * Compute (consentStatus, effectiveStatus, validUntil, daysRemaining, warningLevel).
     *
     * effectiveStatus reconciles the stored enum with valid_until: a stored
     * 'active' whose valid_until < now() becomes 'expired' (lazy enum drift
     * between expiry and the next sync run that flips it).
     *
     * @return array{0: string, 1: string, 2: ?Carbon, 3: ?int, 4: 'green'|'yellow'|'red'|'na'}
     */
    private function computeConsentFields(?object $conn, Carbon $generatedAt): array
    {
        if ($conn === null) {
            return ['none', 'none', null, null, 'na'];
        }

        $stored = (string) ($conn->status ?? '');
        $rawValidUntil = $conn->valid_until ?? null;
        $validUntil = $rawValidUntil !== null ? Carbon::parse((string) $rawValidUntil) : null;

        $effective = $stored;
        if ($stored === BankConnectionStatus::Active->value
            && $validUntil !== null
            && $validUntil->lt($generatedAt)
        ) {
            $effective = BankConnectionStatus::Expired->value;
        }

        $daysRemaining = null;
        $warningLevel = 'red';

        if ($effective === BankConnectionStatus::Active->value) {
            if ($validUntil !== null) {
                // Days remaining: floor of the diff so T-3 means "fewer than
                // 3 full days left from generatedAt". Use the inDays helper
                // with absolute=false so a negative remainder (already past
                // expiry on the same row) is reflected as <=0.
                $diffSeconds = $generatedAt->diffInSeconds($validUntil, false);
                $daysRemaining = (int) floor($diffSeconds / 86400);

                if ($daysRemaining > Thresholds::CONSENT_YELLOW_DAYS) {
                    $warningLevel = 'green';
                } elseif ($daysRemaining > Thresholds::CONSENT_RED_DAYS) {
                    $warningLevel = 'yellow';
                } else {
                    $warningLevel = 'red';
                }
            } else {
                // Defensive: schema says NOT NULL, but treat absence as 'na'.
                $warningLevel = 'na';
            }
        } else {
            // expired / revoked / failed / superseded → red.
            // 'none' is handled above with an early return.
            $warningLevel = 'red';
        }

        return [$stored, $effective, $validUntil, $daysRemaining, $warningLevel];
    }

    private function isStale(?Carbon $lastSyncedAt, Carbon $generatedAt): bool
    {
        if ($lastSyncedAt === null) {
            return true;
        }

        $threshold = $generatedAt->copy()->subHours(Thresholds::SYNC_STALE_HOURS);

        return $lastSyncedAt->lt($threshold);
    }

    /**
     * @param  list<BankRow>  $banks
     * @param  list<StuckTransactionRow>  $stuck
     * @param  list<RecentErrorRow>  $recentErrors
     */
    private function assemble(array $banks, array $stuck, array $recentErrors, Carbon $generatedAt): StatusSnapshot
    {
        $hasRedOrStuck = false;
        foreach ($banks as $b) {
            if (! $b->bankActive) {
                continue;
            }
            if ($b->consentWarningLevel === 'red') {
                $hasRedOrStuck = true;
                break;
            }
            if ($b->syncStale) {
                $hasRedOrStuck = true;
                break;
            }
        }

        if (! $hasRedOrStuck && $stuck !== []) {
            $hasRedOrStuck = true;
        }

        // Recent errors keep the snapshot non-empty even when there are no
        // active banks or stuck rows — otherwise StatusRenderer's
        // "Nothing to show" early-return would suppress the panel that's
        // the whole reason this work exists.
        $isEmpty = $banks === [] && $stuck === [] && $recentErrors === [];

        return new StatusSnapshot(
            banks: $banks,
            stuckTransactions: $stuck,
            hasRedOrStuckRows: $hasRedOrStuck,
            isEmpty: $isEmpty,
            generatedAt: $generatedAt,
            recentErrors: $recentErrors,
        );
    }

    /**
     * Load up to RECENT_ERRORS_LIMIT rows from the union of sync_run_errors
     * and push_run_errors created within RECENT_ERRORS_WINDOW_HOURS of
     * generatedAt, ordered by created_at DESC.
     *
     * Sync errors may have bank_account_id = NULL when the failure was
     * tied to a connection rather than a specific account (e.g. consent
     * revoked); for those rows bankDisplayName/accountDisplayName are null
     * and the renderer falls back to a dash.
     *
     * Mock-bank filtering mirrors the rest of the builder: rows tied to
     * banks.slug = 'mock' are excluded when $includeMock is false.
     *
     * @return list<RecentErrorRow>
     */
    private function loadRecentErrors(bool $includeMock, Carbon $generatedAt): array
    {
        $cutoff = $generatedAt->copy()->subHours(Thresholds::RECENT_ERRORS_WINDOW_HOURS);

        // Connection-level sync errors have bank_account_id IS NULL, so the
        // bank_accounts → banks join can't be the only source of truth for
        // the mock filter — we'd otherwise leak mock-bank errors through
        // when `includeMock=false`. sync_runs.bank_slug is denormalised onto
        // the run row and gives us a fallback bank attribution.
        $syncQuery = DB::table('sync_run_errors as sre')
            ->leftJoin('bank_accounts as ba', 'sre.bank_account_id', '=', 'ba.id')
            ->leftJoin('banks as b', 'ba.bank_slug', '=', 'b.slug')
            ->leftJoin('sync_runs as sr', 'sre.sync_run_id', '=', 'sr.id')
            ->where('sre.created_at', '>=', $cutoff);

        if (! $includeMock) {
            // A row is "mock" if either the joined account's bank or the
            // run's denormalised bank_slug is 'mock'. Everything else
            // (including rows where both are null, which shouldn't happen
            // but is defensive) passes through.
            $syncQuery->where(function ($q): void {
                $q->where(function ($inner): void {
                    $inner->whereNull('b.slug')->orWhere('b.slug', '!=', 'mock');
                })->where(function ($inner): void {
                    $inner->whereNull('sr.bank_slug')->orWhere('sr.bank_slug', '!=', 'mock');
                });
            });
        }

        $syncRows = $syncQuery
            ->orderByDesc('sre.created_at')
            ->limit(Thresholds::RECENT_ERRORS_LIMIT)
            ->select([
                'sre.created_at as created_at',
                'sre.sync_run_id as run_id',
                'sre.http_status as http_status',
                'sre.error_detail as error_detail',
                'b.display_name as bank_display_name',
                'ba.display_name as bank_account_display_name',
                'ba.iban as bank_account_iban',
            ])
            ->get();

        $pushQuery = DB::table('push_run_errors as pre')
            ->leftJoin('transactions as t', 'pre.transaction_id', '=', 't.id')
            ->leftJoin('bank_accounts as ba', 't.bank_account_id', '=', 'ba.id')
            ->leftJoin('banks as b', 'ba.bank_slug', '=', 'b.slug')
            ->where('pre.created_at', '>=', $cutoff);

        if (! $includeMock) {
            $pushQuery->where(function ($q): void {
                $q->whereNull('b.slug')->orWhere('b.slug', '!=', 'mock');
            });
        }

        $pushRows = $pushQuery
            ->orderByDesc('pre.created_at')
            ->limit(Thresholds::RECENT_ERRORS_LIMIT)
            ->select([
                'pre.created_at as created_at',
                'pre.push_run_id as run_id',
                'pre.http_status as http_status',
                'pre.error_detail as error_detail',
                'b.display_name as bank_display_name',
                'ba.display_name as bank_account_display_name',
                'ba.iban as bank_account_iban',
            ])
            ->get();

        /** @var list<RecentErrorRow> $combined */
        $combined = [];

        foreach ($syncRows as $r) {
            $combined[] = new RecentErrorRow(
                createdAt: Carbon::parse((string) $r->created_at),
                runKind: 'sync',
                runId: (int) $r->run_id,
                httpStatus: $r->http_status !== null ? (int) $r->http_status : null,
                bankDisplayName: $r->bank_display_name !== null ? (string) $r->bank_display_name : null,
                bankAccountDisplayName: $this->accountLabel($r),
                detail: (string) $r->error_detail,
            );
        }

        foreach ($pushRows as $r) {
            $combined[] = new RecentErrorRow(
                createdAt: Carbon::parse((string) $r->created_at),
                runKind: 'push',
                runId: (int) $r->run_id,
                httpStatus: $r->http_status !== null ? (int) $r->http_status : null,
                bankDisplayName: $r->bank_display_name !== null ? (string) $r->bank_display_name : null,
                bankAccountDisplayName: $this->accountLabel($r),
                detail: (string) $r->error_detail,
            );
        }

        usort(
            $combined,
            fn (RecentErrorRow $a, RecentErrorRow $b): int => $b->createdAt <=> $a->createdAt,
        );

        return array_slice($combined, 0, Thresholds::RECENT_ERRORS_LIMIT);
    }

    /**
     * Pick a human-readable label for an account row, preferring the
     * display_name then the IBAN. Returns null when both are absent
     * (sync errors with bank_account_id IS NULL).
     */
    private function accountLabel(object $row): ?string
    {
        if (property_exists($row, 'bank_account_display_name') && $row->bank_account_display_name !== null) {
            return (string) $row->bank_account_display_name;
        }
        if (property_exists($row, 'bank_account_iban') && $row->bank_account_iban !== null) {
            return (string) $row->bank_account_iban;
        }

        return null;
    }
}
