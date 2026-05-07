<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase
{
    public function test_no_rules_returns_trimmed_remittance(): void
    {
        $engine = new RuleEngine;

        $this->assertSame('EXAMPLE COMPANY SRL', $engine->apply('  EXAMPLE COMPANY SRL  ', []));
    }

    public function test_single_rule_match_returns_replacement(): void
    {
        $engine = new RuleEngine;
        $rule = new Rule(
            name: 'compra',
            description: 'BCP card purchase',
            pattern: '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
            replacement: '$1',
            postHooks: [],
            fixtures: [],
        );

        $this->assertSame('SHOP LISBOA', $engine->apply('COMPRA 5962 SHOP LISBOA', [$rule]));
    }

    public function test_no_match_returns_trimmed_remittance(): void
    {
        $engine = new RuleEngine;
        $rule = new Rule('foo', 'desc', '/^DD\s+(.+)$/i', '$1', [], []);

        $this->assertSame('SHOP 12345', $engine->apply('SHOP 12345', [$rule]));
    }

    public function test_first_terminal_match_wins(): void
    {
        $engine = new RuleEngine;
        $specific = new Rule(
            'air-serbia',
            'specific',
            '/^COMPRA\s+\d{3,5}\s+(AIR\s+SERBIA)\s+[A-Z]\d{8,}\s+(.+)$/i',
            '$1 $2',
            [],
            [],
        );
        $general = new Rule(
            'compra-generic',
            'general',
            '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
            '$1',
            [],
            [],
        );

        $this->assertSame(
            'AIR SERBIA Belgrade RS',
            $engine->apply('COMPRA 5962 AIR SERBIA A21296337235 Belgrade RS', [$specific, $general]),
        );
    }

    public function test_post_hooks_applied_in_order(): void
    {
        $engine = new RuleEngine;
        $rule = new Rule(
            'lev-atm',
            'BCP ATM with location',
            '/^LEV\s+ATM\s+\d+\s+\d+\s+(.+?)\s{4,}\S.*$/i',
            'ATM $1',
            ['collapse'],
            [],
        );

        // VILA  NOVA inside location → ATM VILA NOVA after collapse
        $this->assertSame(
            'ATM VILA NOVA',
            $engine->apply('LEV ATM 5962 703   VILA  NOVA        Mario Nunes E', [$rule]),
        );
    }

    public function test_empty_result_after_post_hooks_falls_through_to_next_rule(): void
    {
        // Rule that matches but produces empty output should not block
        // subsequent rules from firing.
        $engine = new RuleEngine;
        $emptier = new Rule(
            'wipe',
            'replaces with empty',
            '/^STARTS-WITH.*$/',
            '',
            ['trim'],
            [],
        );
        $catchAll = new Rule(
            'catch',
            'matches anything',
            '/^(.*)$/',
            'CAUGHT',
            [],
            [],
        );

        $this->assertSame('CAUGHT', $engine->apply('STARTS-WITH stuff', [$emptier, $catchAll]));
    }
}
