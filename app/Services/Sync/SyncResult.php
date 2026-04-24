<?php

namespace App\Services\Sync;

use App\Models\SyncRun;

/** @immutable */
final class SyncResult
{
    public function __construct(
        public readonly SyncRun $run,
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $deduped,
        public readonly int $errors,
    ) {}
}
