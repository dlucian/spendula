<?php

namespace App\Services\Locks;

use RuntimeException;

/** Raised when a Spendula advisory lock is already held by another process. */
class LockBusyException extends RuntimeException {}
