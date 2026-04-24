<?php

namespace App\Services\EnableBanking\Exceptions;

use RuntimeException;

/**
 * Base exception for all Enable Banking error surfaces. Callers match on the
 * concrete subclass to implement SPEC §10.1 behaviour (hard fail, mark
 * connection revoked, abort cleanly, etc.).
 */
class EnableBankingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message);
    }
}
