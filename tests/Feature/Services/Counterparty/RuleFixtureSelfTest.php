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
     * Both `rules` (L2 remittance) and `name_rules` (L0/L1 names) are
     * exercised — same engine, only the input string and the call site
     * differ at the resolver, so a fixture mismatch in either list
     * surfaces here.
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
        foreach (['rules' => $loader->available(), 'name_rules' => $loader->availableNameRules()] as $kind => $rulesByBank) {
            foreach ($rulesByBank as $bankSlug => $rules) {
                foreach ($rules as $rule) {
                    foreach ($rule->fixtures as $fixture) {
                        // Match the resolver-level contract for each
                        // bucket: name_rules go through applyForName
                        // (L0/L1 semantics), rules through apply (L2).
                        $actual = $kind === 'name_rules'
                            ? $engine->applyForName($fixture->input, $rules)
                            : $engine->apply($fixture->input, $rules);
                        $this->assertSame(
                            $fixture->expected,
                            $actual,
                            "Rule {$bankSlug}/{$kind}/{$rule->name}: input '{$fixture->input}' should resolve to '{$fixture->expected}', got '{$actual}'",
                        );
                        $totalFixtures++;
                    }
                }
            }
        }

        // Sanity check: the test discovered something. If the available
        // dir is empty, future regressions wouldn't be caught silently.
        $this->assertGreaterThan(0, $totalFixtures, 'No rule fixtures discovered — is config/counterparty-rules-available/ empty?');
    }
}
