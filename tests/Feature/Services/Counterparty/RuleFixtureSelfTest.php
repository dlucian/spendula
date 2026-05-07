<?php

namespace Tests\Feature\Services\Counterparty;

use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use Tests\TestCase;

class RuleFixtureSelfTest extends TestCase
{
    /**
     * Auto-discovered fixture self-test: walks every rule in every
     * config/counterparty-rules-available/*.json file and asserts
     * that the engine returns the expected output for each fixture.
     *
     * Failures show up as one assertion error per failing fixture
     * with the bank slug, rule name, and input/expected/actual in
     * the message — no need to track down which rule broke.
     */
    public function test_every_available_rule_fixture_passes(): void
    {
        $loader = new RuleLoader(base_path('config/counterparty-rules-available'));
        $engine = new RuleEngine;

        $totalFixtures = 0;
        foreach ($loader->available() as $bankSlug => $rules) {
            foreach ($rules as $rule) {
                foreach ($rule->fixtures as $fixture) {
                    $actual = $engine->apply($fixture->input, $rules);
                    $this->assertSame(
                        $fixture->expected,
                        $actual,
                        "Rule {$bankSlug}/{$rule->name}: input '{$fixture->input}' should resolve to '{$fixture->expected}', got '{$actual}'",
                    );
                    $totalFixtures++;
                }
            }
        }

        // Sanity check: the test discovered something. If the available
        // dir is empty, future regressions wouldn't be caught silently.
        $this->assertGreaterThan(0, $totalFixtures, 'No rule fixtures discovered — is config/counterparty-rules-available/ empty?');
    }
}
