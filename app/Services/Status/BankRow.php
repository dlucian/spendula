<?php

namespace App\Services\Status;

use Illuminate\Support\Carbon;

/**
 * One row in the spendula:status per-bank section.
 *
 * `consentStatus` mirrors `bank_connections.status` on the selected
 * (active-or-most-recent) connection. `effectiveConsentStatus` reconciles
 * that stored enum with `valid_until`: a stored 'active' whose
 * `valid_until` is in the past becomes effectively 'expired' (lazy enum
 * drift between expiry and the next sync run that flips it).
 *
 * `consentWarningLevel` is one of:
 *   - 'green' — comfortably valid (> CONSENT_YELLOW_DAYS remaining)
 *   - 'yellow' — within CONSENT_YELLOW_DAYS but more than CONSENT_RED_DAYS
 *   - 'red' — within CONSENT_RED_DAYS, OR effectively expired/revoked/failed
 *   - 'na' — no connection at all (active=true bank without any auth row)
 *
 * `lastSyncedAt` is sourced from `bank_connections.last_synced_at` on the
 * selected connection — NOT from `bank_account_sync_state`. SyncRunner only
 * stamps `bank_connections.last_synced_at` when every attempted account on
 * that connection succeeded, which is the right "freshness" signal for a
 * dashboard. See DECISIONS.md.
 *
 * `syncStale` is true only when an active-bank with active-effective-consent
 * has not synced in the last SYNC_STALE_HOURS (or has never synced at all).
 * Other bank states do not generate stale-sync warnings — the consent state
 * already covers them.
 *
 * @immutable
 *
 * @phpstan-type WarningLevel 'green'|'yellow'|'red'|'na'
 * @phpstan-type ConsentStatus 'active'|'superseded'|'expired'|'revoked'|'failed'|'none'
 */
final class BankRow
{
    /**
     * @param  array{fetched: int, approved: int, transfer: int, tracking: int}  $queuedCounts
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $displayName,
        public readonly bool $bankActive,
        public readonly ?Carbon $consentValidUntil,
        public readonly ?int $consentDaysRemaining,
        public readonly string $consentStatus,
        public readonly string $effectiveConsentStatus,
        public readonly string $consentWarningLevel,
        public readonly array $queuedCounts,
        public readonly ?Carbon $lastSyncedAt,
        public readonly ?Carbon $lastPushedAt,
        public readonly ?Carbon $lastSnapshotAt,
        public readonly bool $syncStale,
    ) {}
}
