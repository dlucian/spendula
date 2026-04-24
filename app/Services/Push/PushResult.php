<?php

namespace App\Services\Push;

use App\Models\PushRun;

/** @immutable */
final class PushResult
{
    public function __construct(
        public readonly PushRun $run,
        public readonly int $pushed,
        public readonly int $duplicate,
        public readonly int $errors,
    ) {}
}
