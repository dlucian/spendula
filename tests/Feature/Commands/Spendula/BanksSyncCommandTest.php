<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\PsuType;
use App\Models\Bank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BanksSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_configured_banks(): void
    {
        Config::set('spendula-banks', [
            'mock' => [
                'display_name' => 'Mock ASPSP',
                'aspsp_name' => 'Mock ASPSP',
                'aspsp_country' => 'FI',
                'psu_type' => 'personal',
                'default_currency' => 'EUR',
                'sync_lookback_days' => 90,
            ],
        ]);

        $this->artisan('spendula:banks:sync')->assertSuccessful();

        $mock = Bank::query()->findOrFail('mock');

        $this->assertSame('Mock ASPSP', $mock->display_name);
        $this->assertSame('FI', $mock->aspsp_country);
        $this->assertSame(PsuType::Personal, $mock->psu_type);
        $this->assertTrue($mock->active);
        $this->assertSame(90, $mock->sync_lookback_days);
    }

    public function test_second_run_is_a_no_op(): void
    {
        Config::set('spendula-banks', [
            'mock' => [
                'display_name' => 'Mock ASPSP',
                'aspsp_name' => 'Mock ASPSP',
                'aspsp_country' => 'FI',
                'psu_type' => 'personal',
                'default_currency' => 'EUR',
                'sync_lookback_days' => 90,
            ],
        ]);

        $this->artisan('spendula:banks:sync')->assertSuccessful();
        $firstUpdatedAt = Bank::query()->findOrFail('mock')->updated_at;

        // Sleep 1s so updated_at would move if anything actually updated.
        sleep(1);

        $this->artisan('spendula:banks:sync')->assertSuccessful();

        $this->assertSame(1, Bank::query()->count());
        $this->assertEquals($firstUpdatedAt, Bank::query()->findOrFail('mock')->updated_at);
    }

    public function test_preserves_operator_added_banks_not_in_config(): void
    {
        // Operator-added bank: directly inserted into the DB, never named in
        // config/spendula-banks.php. banks:sync must leave it untouched so the
        // public source tree never lists which banks the operator uses.
        Bank::query()->create([
            'slug' => 'operator-added',
            'display_name' => 'Operator Added',
            'aspsp_name' => 'Operator Added',
            'aspsp_country' => 'PT',
            'psu_type' => PsuType::Personal,
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        Config::set('spendula-banks', [
            'mock' => [
                'display_name' => 'Mock ASPSP',
                'aspsp_name' => 'Mock ASPSP',
                'aspsp_country' => 'FI',
                'psu_type' => 'personal',
                'default_currency' => 'EUR',
                'sync_lookback_days' => 90,
            ],
        ]);

        $this->artisan('spendula:banks:sync')->assertSuccessful();

        $this->assertSame(2, Bank::query()->count());
        $this->assertTrue(Bank::query()->findOrFail('mock')->active);
        $this->assertTrue(
            Bank::query()->findOrFail('operator-added')->active,
            'Operator-added bank must stay active after a sync that does not mention it.'
        );
    }
}
