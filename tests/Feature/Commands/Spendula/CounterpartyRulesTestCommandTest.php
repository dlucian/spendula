<?php

namespace Tests\Feature\Commands\Spendula;

use Tests\TestCase;

class CounterpartyRulesTestCommandTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/spendula-rules-test-cmd-'.uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob("{$this->tempDir}/*") ?: []);
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function writeRules(string $bank, array $rules): void
    {
        file_put_contents(
            "{$this->tempDir}/{$bank}.json",
            json_encode(['name' => $bank, 'rules' => $rules]),
        );
    }

    public function test_succeeds_when_all_fixtures_pass(): void
    {
        $this->writeRules('bcp', [
            [
                'name' => 'compra',
                'description' => 'card purchase',
                'pattern' => '/^COMPRA\\s+\\d+\\s+(.+)$/i',
                'replacement' => '$1',
                'tests' => [['in' => 'COMPRA 5962 SHOP', 'out' => 'SHOP']],
            ],
        ]);

        $this->artisan("spendula:counterparty:rules:test --dir={$this->tempDir}")
            ->expectsOutputToContain('Passed: 1')
            ->expectsOutputToContain('Failed: 0')
            ->assertSuccessful();
    }

    public function test_fails_when_a_fixture_fails(): void
    {
        $this->writeRules('bcp', [
            [
                'name' => 'broken',
                'description' => 'broken rule',
                'pattern' => '/^X$/',
                'replacement' => 'Y',
                'tests' => [['in' => 'X', 'out' => 'Z']],  // expected Z but rule produces Y
            ],
        ]);

        $this->artisan("spendula:counterparty:rules:test --dir={$this->tempDir}")
            ->expectsOutputToContain('FAIL')
            ->expectsOutputToContain('bcp/broken')
            ->assertFailed();
    }

    public function test_filters_by_bank_when_option_given(): void
    {
        $this->writeRules('bcp', [
            ['name' => 'r1', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => 'Y', 'tests' => [['in' => 'X', 'out' => 'Y']]],
        ]);
        $this->writeRules('ing-ro', [
            ['name' => 'r2', 'description' => 'd', 'pattern' => '/^A$/', 'replacement' => 'B', 'tests' => [['in' => 'A', 'out' => 'C']]],  // failing
        ]);

        $this->artisan("spendula:counterparty:rules:test --dir={$this->tempDir} --bank=bcp")
            ->expectsOutputToContain('Passed: 1')
            ->assertSuccessful();
    }

    public function test_fails_when_no_fixtures_found(): void
    {
        $this->artisan("spendula:counterparty:rules:test --bank=does-not-exist --dir={$this->tempDir}")
            ->expectsOutputToContain('No rule fixtures')
            ->assertFailed();
    }
}
