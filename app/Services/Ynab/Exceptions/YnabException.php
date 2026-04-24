<?php

namespace App\Services\Ynab\Exceptions;

use RuntimeException;

class YnabException extends RuntimeException
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
