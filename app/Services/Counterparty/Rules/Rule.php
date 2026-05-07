<?php

namespace App\Services\Counterparty\Rules;

/**
 * Counterparty cleanup rule: a regex pattern + replacement template +
 * optional named post-hooks. First terminal match wins in the engine
 * (per-bank rule list, ordered most-specific first).
 *
 * Pattern is a full PCRE (delimiters + flags included). Replacement
 * uses preg_replace backreference syntax ($1, $2, ...). Post hooks
 * are named finalizers applied to the substitution result; v1 ships
 * "trim" and "collapse" (see PostHook::HOOKS).
 */
final readonly class Rule
{
    /**
     * @param  string[]  $postHooks
     * @param  RuleFixture[]  $fixtures
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $pattern,
        public string $replacement,
        public array $postHooks,
        public array $fixtures,
    ) {}
}
