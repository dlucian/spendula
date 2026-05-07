<?php

namespace Tests\Feature\Commands\Spendula;

use Tests\TestCase;

class CounterpartyRulesEnableCommandTest extends TestCase
{
    public function test_creates_symlink_pointing_to_available_file(): void
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'bcp-test-'.uniqid();

        // Set up: a rule file exists in available/.
        file_put_contents("{$availableDir}/{$bank}.json", json_encode([
            'name' => 'Test', 'rules' => [],
        ]));

        $this->artisan("spendula:counterparty:rules:enable {$bank}")
            ->expectsOutputToContain('Enabled')
            ->assertSuccessful();

        $this->assertTrue(is_link("{$enabledDir}/{$bank}.json"));
        $this->assertSame(
            "../counterparty-rules-available/{$bank}.json",
            readlink("{$enabledDir}/{$bank}.json"),
        );

        // Cleanup
        unlink("{$enabledDir}/{$bank}.json");
        unlink("{$availableDir}/{$bank}.json");
    }

    public function test_idempotent_when_already_enabled(): void
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank = 'bcp-test-'.uniqid();

        file_put_contents("{$availableDir}/{$bank}.json", json_encode(['name' => 'T', 'rules' => []]));
        symlink("../counterparty-rules-available/{$bank}.json", "{$enabledDir}/{$bank}.json");

        $this->artisan("spendula:counterparty:rules:enable {$bank}")
            ->expectsOutputToContain('already enabled')
            ->assertSuccessful();

        unlink("{$enabledDir}/{$bank}.json");
        unlink("{$availableDir}/{$bank}.json");
    }

    public function test_fails_when_no_available_file(): void
    {
        $bank = 'nonexistent-'.uniqid();

        $this->artisan("spendula:counterparty:rules:enable {$bank}")
            ->expectsOutputToContain('No rule file')
            ->assertFailed();
    }

    public function test_enable_all_creates_symlinks_for_every_available_file(): void
    {
        $availableDir = base_path('config/counterparty-rules-available');
        $enabledDir = base_path('config/counterparty-rules-enabled');
        $bank1 = 'enableall-1-'.uniqid();
        $bank2 = 'enableall-2-'.uniqid();

        file_put_contents("{$availableDir}/{$bank1}.json", json_encode(['name' => 'B1', 'rules' => []]));
        file_put_contents("{$availableDir}/{$bank2}.json", json_encode(['name' => 'B2', 'rules' => []]));

        try {
            $this->artisan('spendula:counterparty:rules:enable --all')
                ->expectsOutputToContain('Enabled')
                ->assertSuccessful();

            $this->assertTrue(is_link("{$enabledDir}/{$bank1}.json"));
            $this->assertTrue(is_link("{$enabledDir}/{$bank2}.json"));
        } finally {
            @unlink("{$enabledDir}/{$bank1}.json");
            @unlink("{$enabledDir}/{$bank2}.json");
            @unlink("{$availableDir}/{$bank1}.json");
            @unlink("{$availableDir}/{$bank2}.json");
        }
    }

    public function test_fails_when_no_bank_and_no_all_flag(): void
    {
        $this->artisan('spendula:counterparty:rules:enable')
            ->expectsOutputToContain('--all')
            ->assertFailed();
    }
}
