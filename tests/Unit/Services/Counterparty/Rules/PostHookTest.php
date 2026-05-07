<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\PostHook;
use PHPUnit\Framework\TestCase;

class PostHookTest extends TestCase
{
    public function test_trim_strips_whitespace(): void
    {
        $this->assertSame('hello', PostHook::apply('trim', '  hello  '));
    }

    public function test_trim_strips_punctuation_set(): void
    {
        // The "trim" hook strips small punctuation (-_.,;:) plus whitespace
        // — handles BCP's "EDP COMERCIAL-" hyphen artifact and similar.
        $this->assertSame('EDP COMERCIAL', PostHook::apply('trim', 'EDP COMERCIAL-'));
        $this->assertSame('hello', PostHook::apply('trim', '_hello,'));
    }

    public function test_collapse_replaces_internal_whitespace_runs_with_single_space(): void
    {
        $this->assertSame('VILA NOVA', PostHook::apply('collapse', 'VILA  NOVA'));
        $this->assertSame('a b c', PostHook::apply('collapse', "a\t\tb   c"));
    }

    public function test_unknown_hook_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown post hook.*foo/i');

        PostHook::apply('foo', 'anything');
    }

    public function test_known_returns_supported_hooks(): void
    {
        $known = PostHook::known();

        $this->assertContains('trim', $known);
        $this->assertContains('collapse', $known);
    }
}
