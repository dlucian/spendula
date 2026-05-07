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
     * Apply rules and always return a string. On no terminal match, returns
     * trim($remittance). Suitable for L2 remittance cleanup, where a missing
     * rule file (or no match) should still hand back a usable string.
     *
     * @param  Rule[]  $rules
     */
    public function apply(string $remittance, array $rules): string
    {
        $outcome = $this->resolveOutcome($remittance, $rules);

        // L2 contract: terminal rewrite wins; otherwise fall back to the
        // trimmed input. blanked/unmatched both collapse to trim() at L2,
        // because the L2 remitter never had per-level fall-through to begin with.
        return $outcome->result ?? trim($remittance);
    }

    /**
     * Apply name-side rules with resolver L0/L1 semantics, returning the
     * fixture-comparable string the operator wrote in their `out` column.
     *
     * Translation:
     *   - rewritten($x)  → $x (the rewrite, untruncated; truncation is a
     *                     resolver-output concern, not a rule concern)
     *   - blanked        → '' (empty output is the operator's signal that
     *                     a suppressive rule fired)
     *   - unmatched      → $input verbatim (whitespace preserved, length
     *                     untouched — same contract Resolver gives at L0/L1)
     *
     * Used by both spendula:counterparty:rules:test and
     * RuleFixtureSelfTest for the `name_rules` bucket. Without it, a
     * whitespace-sensitive fixture like {in: '  ACME  ', out: '  ACME  '}
     * or a suppressive fixture like {in: 'SELF', out: ''} would
     * mis-evaluate against the L2 engine semantics.
     *
     * @param  Rule[]  $rules
     */
    public function applyForName(string $name, array $rules): string
    {
        $outcome = $this->resolveOutcome($name, $rules);

        if ($outcome->result !== null) {
            return $outcome->result;
        }

        return $outcome->matched ? '' : $name;
    }

    /**
     * Apply rules and report the operator's intent as a tri-state outcome.
     *
     * Iteration follows the same first-terminal-match-wins rule as apply():
     * a rule whose post-processed result is non-empty terminates the loop
     * with `RuleOutcome::rewritten(...)`. A rule that matches but produces
     * an empty post-processed result is treated as "matched but blanked"
     * and the loop continues — but the matched flag is sticky, so even if
     * no later rule produces a terminal match, the outcome is
     * `RuleOutcome::blanked()` (not `unmatched()`). When no rule's pattern
     * matched at all, returns `RuleOutcome::unmatched()`.
     *
     * The Resolver uses this at L0/L1 to distinguish:
     *   - unmatched → return the raw L0/L1 name verbatim
     *   - rewritten → return the rewritten string at the same level
     *   - blanked   → fall through to the next ladder step (L0 → L1, L1 → L2)
     *
     * @param  Rule[]  $rules
     */
    public function resolveOutcome(string $remittance, array $rules): RuleOutcome
    {
        $anyMatched = false;
        foreach ($rules as $rule) {
            if (preg_match($rule->pattern, $remittance) !== 1) {
                continue;
            }
            $anyMatched = true;

            $result = preg_replace($rule->pattern, $rule->replacement, $remittance, 1);
            if (! is_string($result)) {
                continue;
            }

            foreach ($rule->postHooks as $hook) {
                $result = PostHook::apply($hook, $result);
            }

            $result = trim($result);
            if ($result !== '') {
                return RuleOutcome::rewritten($result);
            }
        }

        return $anyMatched ? RuleOutcome::blanked() : RuleOutcome::unmatched();
    }
}
