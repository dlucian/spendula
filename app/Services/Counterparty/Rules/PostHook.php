<?php

namespace App\Services\Counterparty\Rules;

use InvalidArgumentException;

/**
 * Named finalizers applied to a Rule's preg_replace result. v1 ships
 * "trim" (strip leading/trailing whitespace + small punctuation) and
 * "collapse" (replace internal whitespace runs with a single space).
 *
 * New hooks are added by extending HOOKS and adding a private static
 * helper. The named-set design keeps rule files self-contained — no
 * inline PHP code in JSON, no eval.
 */
final class PostHook
{
    /**
     * @var list<string>
     */
    private const array HOOKS = ['trim', 'collapse'];

    /** Characters trimmed by the "trim" hook beyond whitespace. */
    private const string TRIM_PUNCTUATION = " \t\n\r\0\x0B-_.,;:";

    public static function apply(string $hook, string $text): string
    {
        return match ($hook) {
            'trim' => trim($text, self::TRIM_PUNCTUATION),
            'collapse' => (string) preg_replace('/\s+/', ' ', $text),
            default => throw new InvalidArgumentException("Unknown post hook: '{$hook}'. Known: ".implode(', ', self::HOOKS)),
        };
    }

    /**
     * @return list<string>
     */
    public static function known(): array
    {
        return self::HOOKS;
    }
}
