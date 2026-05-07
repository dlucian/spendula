<?php

namespace App\Services\Counterparty\Rules;

/**
 * Single test case for a Rule: input remittance string and the expected
 * resolved output after the rule's regex + post-hooks are applied.
 *
 * Fixtures are part of the rule's contract — they're loaded from the
 * rule's JSON file, validated at add-time and load-time, and exercised
 * by RuleFixtureSelfTest at every PHPUnit run.
 */
final readonly class RuleFixture
{
    public function __construct(
        public string $input,
        public string $expected,
    ) {}
}
