<?php

namespace App\Services\Counterparty;

/** @immutable */
final class ResolvedCounterparty
{
    public function __construct(
        public readonly string $name,
        public readonly int $level,
    ) {}
}
