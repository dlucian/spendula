<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\PsuType;
use App\Models\Bank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BanksAddCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_a_bank_with_valid_options(): void
    {
        $this->artisan('spendula:banks:add', [
            '--slug' => 'demo',
            '--display-name' => 'Demo Bank',
            '--aspsp-name' => 'Demo ASPSP',
            '--aspsp-country' => 'pt',
            '--psu-type' => 'personal',
            '--default-currency' => 'eur',
            '--sync-lookback-days' => '60',
        ])->assertSuccessful();

        $bank = Bank::query()->findOrFail('demo');

        $this->assertSame('Demo Bank', $bank->display_name);
        $this->assertSame('Demo ASPSP', $bank->aspsp_name);
        $this->assertSame('PT', $bank->aspsp_country);
        $this->assertSame(PsuType::Personal, $bank->psu_type);
        $this->assertSame('EUR', $bank->default_currency);
        $this->assertSame(60, $bank->sync_lookback_days);
        $this->assertTrue($bank->active);
    }

    public function test_rejects_duplicate_slug(): void
    {
        Bank::query()->create([
            'slug' => 'taken',
            'display_name' => 'Taken Bank',
            'aspsp_name' => 'Taken ASPSP',
            'aspsp_country' => 'PT',
            'psu_type' => PsuType::Personal,
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $this->artisan('spendula:banks:add', [
            '--slug' => 'taken',
            '--display-name' => 'Conflict',
            '--aspsp-name' => 'Conflict ASPSP',
            '--aspsp-country' => 'PT',
            '--default-currency' => 'EUR',
        ])->assertFailed();

        $this->assertSame('Taken Bank', Bank::query()->findOrFail('taken')->display_name);
    }

    public function test_rejects_invalid_country_code(): void
    {
        $this->artisan('spendula:banks:add', [
            '--slug' => 'demo',
            '--display-name' => 'Demo',
            '--aspsp-name' => 'Demo ASPSP',
            '--aspsp-country' => 'PRT',
            '--default-currency' => 'EUR',
        ])->assertFailed();

        $this->assertSame(0, Bank::query()->count());
    }

    public function test_rejects_invalid_slug_shape(): void
    {
        $this->artisan('spendula:banks:add', [
            '--slug' => 'Demo Bank',
            '--display-name' => 'Demo',
            '--aspsp-name' => 'Demo ASPSP',
            '--aspsp-country' => 'PT',
            '--default-currency' => 'EUR',
        ])->assertFailed();

        $this->assertSame(0, Bank::query()->count());
    }

    public function test_rejects_lookback_above_max(): void
    {
        $this->artisan('spendula:banks:add', [
            '--slug' => 'demo',
            '--display-name' => 'Demo',
            '--aspsp-name' => 'Demo ASPSP',
            '--aspsp-country' => 'PT',
            '--default-currency' => 'EUR',
            '--sync-lookback-days' => '120',
        ])->assertFailed();

        $this->assertSame(0, Bank::query()->count());
    }
}
