<?php

namespace App\Console\Commands\Spendula;

use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:rules:test
    {--bank= : Optional bank slug to scope to.}
    {--dir= : Override the available rule directory (testing only).}
')]
#[Description('Run every rule fixture in config/counterparty-rules-available/ (or scope to one bank).')]
class CounterpartyRulesTestCommand extends Command
{
    /**
     * Standalone fixture runner — same logic as the auto-discovered
     * RuleFixtureSelfTest, invokable without the test framework.
     * Useful when iterating on rules at the CLI without firing up
     * the full PHPUnit suite.
     *
     * Reads from available/ (not enabled/) so every shipped rule is
     * exercised regardless of operator enable state. The --dir option
     * lets feature tests point at a temp dir without rebinding the
     * service container.
     */
    public function handle(RuleEngine $engine): int
    {
        $dir = (string) $this->option('dir');
        if ($dir === '') {
            $dir = base_path('config/counterparty-rules-available');
        }
        $loader = new RuleLoader($dir);

        $bank = (string) $this->option('bank');
        if ($bank !== '') {
            $bundles = [
                'rules' => [$bank => $loader->forBank($bank)],
                'name_rules' => [$bank => $loader->nameRulesForBank($bank)],
            ];
        } else {
            $bundles = [
                'rules' => $loader->available(),
                'name_rules' => $loader->availableNameRules(),
            ];
        }

        $passed = 0;
        $failed = 0;
        foreach ($bundles as $kind => $rulesByBank) {
            foreach ($rulesByBank as $slug => $rules) {
                foreach ($rules as $rule) {
                    foreach ($rule->fixtures as $fixture) {
                        // Each bucket has its own resolver-level
                        // contract: `rules` are applied at L2 (engine
                        // apply() collapses no-match to trim($input)),
                        // `name_rules` at L0/L1 (raw on no-match,
                        // empty on suppressive blank, rewrite on hit).
                        $actual = $kind === 'name_rules'
                            ? $engine->applyForName($fixture->input, $rules)
                            : $engine->apply($fixture->input, $rules);
                        if ($actual === $fixture->expected) {
                            $passed++;
                        } else {
                            $failed++;
                            $this->error('FAIL');
                            $this->error(sprintf(
                                '[%s/%s/%s]: %s -> %s (expected %s)',
                                $slug,
                                $kind,
                                $rule->name,
                                var_export($fixture->input, true),
                                var_export($actual, true),
                                var_export($fixture->expected, true),
                            ));
                        }
                    }
                }
            }
        }

        $this->line(sprintf('Passed: %d', $passed));
        $this->line(sprintf('Failed: %d', $failed));

        if ($passed === 0 && $failed === 0) {
            $this->error('No rule fixtures found. Did you specify a non-existent --bank or --dir?');

            return self::FAILURE;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
