<?php

namespace App\Services\EnableBanking\Exceptions;

use RuntimeException;

/**
 * A local config/runtime failure that surfaced before any one-shot Enable
 * Banking operation was attempted — typically a missing app id or unreadable
 * private key caught by Client::preflight(). The caller can fix config and
 * retry the same callback URL because consumed_at has not been set; do not
 * route the user toward `spendula:auth:start` for these.
 */
class LocalConfigException extends RuntimeException {}
