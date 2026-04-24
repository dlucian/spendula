<?php

namespace App\Services\Locks;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Postgres session-level advisory locks, scoped to Spendula long-running
 * commands. Keys registered here so pg_locks joins are readable (prefix
 * 42_000_0xx marks a Spendula lock). See SPEC §3.2, CLAUDE.md.
 *
 * Locks are session-level (not transaction-level), so they must be released
 * explicitly — `withLock()` guarantees that via a finally block.
 */
class AdvisoryLock
{
    public const int SYNC = 42_000_001;

    public const int REVIEW = 42_000_002;

    public const int PUSH = 42_000_003;

    public const int TRACKING_SNAPSHOT = 42_000_004;

    public static function tryAcquire(int $key): bool
    {
        $row = DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [$key]);

        return isset($row->acquired) && (bool) $row->acquired;
    }

    public static function release(int $key): bool
    {
        $row = DB::selectOne('SELECT pg_advisory_unlock(?) AS released', [$key]);

        return isset($row->released) && (bool) $row->released;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     *
     * @throws LockBusyException
     */
    public static function withLock(int $key, Closure $fn): mixed
    {
        if (! self::tryAcquire($key)) {
            throw new LockBusyException("Advisory lock {$key} is held by another process.");
        }

        try {
            return $fn();
        } finally {
            self::release($key);
        }
    }
}
