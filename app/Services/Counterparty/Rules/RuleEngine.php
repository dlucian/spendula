<?php

namespace App\Services\Counterparty\Rules;

/**
 * Applies a list of Rule objects to a remittance string. Pure: no I/O,
 * no Laravel dependencies, no logging side effects. Loaders feed it
 * pre-built Rule[] arrays.
 *
 * Semantics:
 *   - Iterate rules in array order; first terminal match wins.
 *   - Terminal match = pattern matches AND post-processed result is
 *     non-empty after trim().
 *   - If a rule matches but post-processing yields empty, fall through
 *     to subsequent rules (so a buggy/over-aggressive rule can't mask
 *     the counterparty entirely).
 *   - No rules match (or all empty out): return trim($remittance).
 */
final class RuleEngine
{
    /**
     * @param  Rule[]  $rules
     */
    public function apply(string $remittance, array $rules): string
    {
        foreach ($rules as $rule) {
            if (preg_match($rule->pattern, $remittance) !== 1) {
                continue;
            }

            $result = preg_replace($rule->pattern, $rule->replacement, $remittance, 1);
            if (! is_string($result)) {
                continue;
            }

            foreach ($rule->postHooks as $hook) {
                $result = PostHook::apply($hook, $result);
            }

            $result = trim($result);
            if ($result !== '') {
                return $result;
            }
        }

        return trim($remittance);
    }
}
