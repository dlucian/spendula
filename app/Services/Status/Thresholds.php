<?php

namespace App\Services\Status;

/**
 * Centralised thresholds for the spendula:status dashboard.
 *
 * SPEC §9.4 prescribes T-14 yellow / T-3 red for consent expiry; the sync-stale
 * and push-stuck thresholds are operator-prompt limits surfaced by Phase 4a.
 * Keeping them in one place means the builder, renderer, and unit tests all
 * reference the same source of truth.
 */
final class Thresholds
{
    /** Days remaining ≤ this trigger a yellow consent warning. */
    public const int CONSENT_YELLOW_DAYS = 14;

    /** Days remaining ≤ this trigger a red consent warning. */
    public const int CONSENT_RED_DAYS = 3;

    /** A bank with active consent + active=true whose last sync is older than this is "stale". */
    public const int SYNC_STALE_HOURS = 24;

    /** A transaction at this attempt count or above counts as stuck. */
    public const int PUSH_STUCK_ATTEMPTS = 5;

    /** Recent-errors panel window: sync/push errors newer than this are shown. */
    public const int RECENT_ERRORS_WINDOW_HOURS = 24;

    /** Recent-errors panel cap: at most this many rows are shown, newest first. */
    public const int RECENT_ERRORS_LIMIT = 10;
}
