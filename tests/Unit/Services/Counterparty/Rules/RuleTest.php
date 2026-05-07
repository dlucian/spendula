<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleFixture;
use PHPUnit\Framework\TestCase;

class RuleTest extends TestCase
{
    public function test_holds_all_required_fields(): void
    {
        $rule = new Rule(
            name: 'compra-card-purchase',
            description: 'BCP card purchase prefix strip',
            pattern: '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
            replacement: '$1',
            postHooks: ['trim'],
            fixtures: [new RuleFixture('COMPRA 5962 SHOP', 'SHOP')],
        );

        $this->assertSame('compra-card-purchase', $rule->name);
        $this->assertSame('BCP card purchase prefix strip', $rule->description);
        $this->assertSame('/^COMPRA\s+\d{3,5}\s+(.+)$/i', $rule->pattern);
        $this->assertSame('$1', $rule->replacement);
        $this->assertSame(['trim'], $rule->postHooks);
        $this->assertCount(1, $rule->fixtures);
        $this->assertInstanceOf(RuleFixture::class, $rule->fixtures[0]);
    }

    public function test_post_hooks_default_empty(): void
    {
        $rule = new Rule(
            name: 'foo',
            description: 'desc',
            pattern: '/^X$/',
            replacement: '',
            postHooks: [],
            fixtures: [new RuleFixture('X', '')],
        );

        $this->assertSame([], $rule->postHooks);
    }
}
