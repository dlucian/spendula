<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\RuleFixture;
use PHPUnit\Framework\TestCase;

class RuleFixtureTest extends TestCase
{
    public function test_holds_input_and_expected(): void
    {
        $fixture = new RuleFixture('COMPRA 5962 SHOP', 'SHOP');

        $this->assertSame('COMPRA 5962 SHOP', $fixture->input);
        $this->assertSame('SHOP', $fixture->expected);
    }
}
