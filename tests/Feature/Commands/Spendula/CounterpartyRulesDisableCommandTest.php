<?php

namespace Tests\Feature\Commands\Spendula;

use Tests\TestCase;

class CounterpartyRulesDisableCommandTest extends TestCase
{
    public function test_removes_symlink_when_enabled(): void
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'bcp-test-'.uniqid();

        file_put_contents("{$availableDir}/{$bank}.json", json_encode(['name' => 'T', 'rules' => []]));
        symlink("../counterparty-rules-available/{$bank}.json", "{$enabledDir}/{$bank}.json");

        $this->artisan("spendula:counterparty:rules:disable {$bank}")
            ->expectsOutputToContain('Disabled')
            ->assertSuccessful();

        $this->assertFalse(file_exists("{$enabledDir}/{$bank}.json"));

        unlink("{$availableDir}/{$bank}.json");
    }

    public function test_idempotent_when_not_enabled(): void
    {
        $bank = 'not-enabled-'.uniqid();

        $this->artisan("spendula:counterparty:rules:disable {$bank}")
            ->expectsOutputToContain('not enabled')
            ->assertSuccessful();
    }

    public function test_refuses_to_remove_a_real_file_thats_not_a_symlink(): void
    {
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'real-file-'.uniqid();

        file_put_contents("{$enabledDir}/{$bank}.json", '{}');

        $this->artisan("spendula:counterparty:rules:disable {$bank}")
            ->expectsOutputToContain('not a symlink')
            ->assertFailed();

        $this->assertTrue(file_exists("{$enabledDir}/{$bank}.json"));
        unlink("{$enabledDir}/{$bank}.json");
    }
}
