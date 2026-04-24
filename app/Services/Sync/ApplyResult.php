<?php

namespace App\Services\Sync;

use App\Models\Transaction;

/** @immutable */
final class ApplyResult
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly ApplyOutcome $outcome,
    ) {}
}
