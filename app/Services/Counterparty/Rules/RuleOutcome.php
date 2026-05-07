<?php

namespace App\Services\Counterparty\Rules;

/**
 * Tri-state result from RuleEngine::resolveOutcome(), used by the
 * Resolver at L0/L1 to distinguish three operator intents:
 *
 *   - **No rule matched** — `matched=false`, `result=null`. The caller
 *     keeps the raw input verbatim (preserves whitespace, doesn't
 *     truncate).
 *   - **Rule matched and produced a non-empty terminal value** —
 *     `matched=true`, `result='<rewritten>'`. The caller returns the
 *     rewrite at the current ladder level.
 *   - **Rule matched and intentionally blanked the name** —
 *     `matched=true`, `result=null`. The caller falls through to the
 *     next ladder step (e.g. L0 → L1, L1 → L2). This enables
 *     suppressive rules like `^SELF TRANSFER$ → ""` to redirect
 *     resolution down the ladder rather than masking the name.
 *
 * The two-flag shape exists because `apply()`'s historical signal —
 * `trim($remittance)` on no-match — conflates "no match" with
 * "matched but empty". L2 callers don't care; L0/L1 callers do.
 */
final readonly class RuleOutcome
{
    public function __construct(
        public bool $matched,
        public ?string $result,
    ) {}

    public static function unmatched(): self
    {
        return new self(false, null);
    }

    public static function rewritten(string $value): self
    {
        return new self(true, $value);
    }

    public static function blanked(): self
    {
        return new self(true, null);
    }
}
