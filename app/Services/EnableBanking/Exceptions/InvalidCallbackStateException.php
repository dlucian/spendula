<?php

namespace App\Services\EnableBanking\Exceptions;

use RuntimeException;

/**
 * The callback's `state` parameter did not match an open, unexpired, unconsumed
 * auth_requests row. Surfaced as HTTP 400 by the controller.
 */
class InvalidCallbackStateException extends RuntimeException {}
