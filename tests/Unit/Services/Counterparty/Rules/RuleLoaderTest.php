<?php

namespace Tests\Unit\Services\Counterparty\Rules;

use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleLoader;
use App\Services\Counterparty\Rules\RuleValidationException;
use PHPUnit\Framework\TestCase;

class RuleLoaderTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/spendula-rules-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = "{$dir}/{$f}";
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
        rmdir($dir);
    }

    private function writeRuleFile(string $filename, array $data): void
    {
        file_put_contents("{$this->tempDir}/{$filename}", json_encode($data));
    }

    public function test_for_bank_returns_empty_when_no_file(): void
    {
        $loader = new RuleLoader($this->tempDir);

        $this->assertSame([], $loader->forBank('bcp'));
    }

    public function test_for_bank_loads_rules_from_matching_filename(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'compra',
                    'description' => 'BCP card purchase',
                    'pattern' => '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
                    'replacement' => '$1',
                    'tests' => [
                        ['in' => 'COMPRA 5962 SHOP', 'out' => 'SHOP'],
                    ],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $rules = $loader->forBank('bcp');

        $this->assertCount(1, $rules);
        $this->assertInstanceOf(Rule::class, $rules[0]);
        $this->assertSame('compra', $rules[0]->name);
        $this->assertCount(1, $rules[0]->fixtures);
        $this->assertSame('COMPRA 5962 SHOP', $rules[0]->fixtures[0]->input);
        $this->assertSame('SHOP', $rules[0]->fixtures[0]->expected);
    }

    public function test_for_bank_returns_empty_for_unknown_bank(): void
    {
        $this->writeRuleFile('bcp.json', ['name' => 'BCP', 'rules' => []]);

        $loader = new RuleLoader($this->tempDir);

        $this->assertSame([], $loader->forBank('revolut-lt'));
    }

    public function test_malformed_json_throws_validation_exception(): void
    {
        file_put_contents("{$this->tempDir}/bad.json", '{ not json');

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/bad\.json/');

        $loader->forBank('bad');
    }

    public function test_missing_top_level_rules_array_throws(): void
    {
        $this->writeRuleFile('bcp.json', ['name' => 'BCP']);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/rules.*missing|missing.*rules/i');

        $loader->forBank('bcp');
    }

    public function test_rule_with_missing_required_field_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                ['name' => 'compra', 'pattern' => '/^X$/'],  // missing description, replacement, tests
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);

        $loader->forBank('bcp');
    }

    public function test_rule_with_empty_tests_array_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'compra',
                    'description' => 'd',
                    'pattern' => '/^X$/',
                    'replacement' => '',
                    'tests' => [],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/tests.*empty|empty.*tests/i');

        $loader->forBank('bcp');
    }

    public function test_rule_with_uncompilable_regex_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'broken',
                    'description' => 'd',
                    'pattern' => '/[broken/',  // unclosed character class
                    'replacement' => '',
                    'tests' => [['in' => 'X', 'out' => 'X']],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/regex|pattern/i');

        $loader->forBank('bcp');
    }

    public function test_rule_with_unknown_post_hook_throws(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                [
                    'name' => 'foo',
                    'description' => 'd',
                    'pattern' => '/^X$/',
                    'replacement' => '',
                    'post' => ['nonexistent-hook'],
                    'tests' => [['in' => 'X', 'out' => 'X']],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/post hook|hook/i');

        $loader->forBank('bcp');
    }

    public function test_available_returns_all_rule_files_keyed_by_bank_slug(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]);
        $this->writeRuleFile('ing-ro.json', [
            'name' => 'ING RO',
            'rules' => [
                ['name' => 'r2', 'description' => 'd', 'pattern' => '/^Y$/', 'replacement' => '', 'tests' => [['in' => 'Y', 'out' => '']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $all = $loader->available();

        $this->assertArrayHasKey('bcp', $all);
        $this->assertArrayHasKey('ing-ro', $all);
        $this->assertCount(1, $all['bcp']);
        $this->assertCount(1, $all['ing-ro']);
    }

    public function test_caches_per_bank_after_first_load(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $first = $loader->forBank('bcp');

        // Mutate the file — cached call should not reflect the change.
        $this->writeRuleFile('bcp.json', ['name' => 'BCP', 'rules' => []]);
        $second = $loader->forBank('bcp');

        $this->assertSame(count($first), count($second));
        $this->assertCount(1, $second);
    }

    public function test_for_bank_throws_on_dangling_symlink(): void
    {
        // Create a symlink pointing to a non-existent target.
        $danglingPath = "{$this->tempDir}/bank.json";
        symlink("{$this->tempDir}/does-not-exist.json", $danglingPath);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/dangling symlink/i');

        $loader->forBank('bank');
    }

    public function test_available_throws_on_dangling_symlink(): void
    {
        $danglingPath = "{$this->tempDir}/bank.json";
        symlink("{$this->tempDir}/does-not-exist.json", $danglingPath);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/dangling symlink/i');

        $loader->available();
    }

    public function test_name_rules_for_bank_returns_empty_when_only_rules_present(): void
    {
        $this->writeRuleFile('bcp.json', [
            'name' => 'BCP',
            'rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->assertSame([], $loader->nameRulesForBank('bcp'));
        // forBank should still load the regular rules.
        $this->assertCount(1, $loader->forBank('bcp'));
    }

    public function test_name_rules_for_bank_returns_empty_when_no_file(): void
    {
        $loader = new RuleLoader($this->tempDir);

        $this->assertSame([], $loader->nameRulesForBank('revolut'));
    }

    public function test_name_rules_for_bank_loads_when_present_alongside_rules(): void
    {
        $this->writeRuleFile('revolut.json', [
            'name' => 'Revolut',
            'rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
            'name_rules' => [
                [
                    'name' => 'bolt',
                    'description' => 'Bolt embedded ID',
                    'pattern' => '/^Bolt\\.eu.*$/i',
                    'replacement' => 'Bolt.eu',
                    'tests' => [['in' => 'Bolt.euo2604281114', 'out' => 'Bolt.eu']],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $remittance = $loader->forBank('revolut');
        $names = $loader->nameRulesForBank('revolut');

        $this->assertCount(1, $remittance);
        $this->assertSame('r1', $remittance[0]->name);
        $this->assertCount(1, $names);
        $this->assertInstanceOf(Rule::class, $names[0]);
        $this->assertSame('bolt', $names[0]->name);
        $this->assertSame('Bolt.eu', $names[0]->replacement);
    }

    public function test_name_rules_with_invalid_regex_throws(): void
    {
        $this->writeRuleFile('revolut.json', [
            'name' => 'Revolut',
            'rules' => [],
            'name_rules' => [
                [
                    'name' => 'broken-name-rule',
                    'description' => 'd',
                    'pattern' => '/[broken/',
                    'replacement' => '',
                    'tests' => [['in' => 'X', 'out' => 'X']],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/regex|pattern/i');

        $loader->nameRulesForBank('revolut');
    }

    public function test_name_rules_with_missing_required_field_throws(): void
    {
        $this->writeRuleFile('revolut.json', [
            'name' => 'Revolut',
            'rules' => [],
            'name_rules' => [
                ['name' => 'missing-tests', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => ''],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);

        $loader->nameRulesForBank('revolut');
    }

    public function test_name_rules_with_failing_fixture_is_caught_by_self_test(): void
    {
        // The loader doesn't run fixtures (RuleFixtureSelfTest does).
        // Validate that the loader at least surfaces empty-tests as it
        // does for `rules`, so the contract is symmetric.
        $this->writeRuleFile('revolut.json', [
            'name' => 'Revolut',
            'rules' => [],
            'name_rules' => [
                [
                    'name' => 'no-tests',
                    'description' => 'd',
                    'pattern' => '/^X$/',
                    'replacement' => '',
                    'tests' => [],
                ],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/tests.*empty|empty.*tests/i');

        $loader->nameRulesForBank('revolut');
    }

    public function test_name_rules_for_bank_validates_required_top_level_rules_key(): void
    {
        // A file with only `name_rules` and no `rules` key is invalid.
        // nameRulesForBank() must surface that — otherwise the failure
        // would only manifest later when L2 resolution calls forBank()
        // for the same bank, making config breakage hard to detect.
        $this->writeRuleFile('revolut.json', [
            'name' => 'Revolut',
            'name_rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/rules.*missing|missing.*rules/i');

        $loader->nameRulesForBank('revolut');
    }

    public function test_name_rules_for_bank_validates_regular_rules_regex(): void
    {
        // A bank file with valid name_rules but a broken regex in `rules`
        // must fail when nameRulesForBank() is called — the loader parses
        // both arrays in one pass so a malformed `rules` cannot lurk
        // until L2 fires.
        $this->writeRuleFile('revolut.json', [
            'name' => 'Revolut',
            'rules' => [
                ['name' => 'broken-l2', 'description' => 'd', 'pattern' => '/[broken/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => 'X']]],
            ],
            'name_rules' => [
                ['name' => 'ok-name', 'description' => 'd', 'pattern' => '/^A$/', 'replacement' => 'B', 'tests' => [['in' => 'A', 'out' => 'B']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches('/regex|pattern/i');

        $loader->nameRulesForBank('revolut');
    }

    public function test_name_rules_for_bank_caches_per_bank(): void
    {
        $this->writeRuleFile('revolut.json', [
            'name' => 'Revolut',
            'rules' => [],
            'name_rules' => [
                ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]);

        $loader = new RuleLoader($this->tempDir);
        $first = $loader->nameRulesForBank('revolut');

        $this->writeRuleFile('revolut.json', ['name' => 'Revolut', 'rules' => [], 'name_rules' => []]);
        $second = $loader->nameRulesForBank('revolut');

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
    }
}
