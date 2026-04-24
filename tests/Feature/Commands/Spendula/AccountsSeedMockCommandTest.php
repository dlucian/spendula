<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountsSeedMockCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bank::query()->create([
            'slug' => 'mock',
            'display_name' => 'Mock ASPSP',
            'aspsp_name' => 'Mock ASPSP',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);
    }

    public function test_maps_a_base_currency_account_to_on_budget(): void
    {
        $account = $this->seedAccount(currency: 'EUR');

        $this->artisan('spendula:accounts:seed-mock', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => '79f0ce5c-5cff-40dd-8560-363caf59b878',
            '--display-name' => 'Main checking',
            '--import-cutoff-date' => '2026-01-01',
        ])->assertSuccessful();

        $account->refresh();

        $this->assertSame('79f0ce5c-5cff-40dd-8560-363caf59b878', $account->ynab_account_id);
        $this->assertSame(YnabAccountType::OnBudget, $account->ynab_account_type);
        $this->assertSame('Main checking', $account->display_name);
        $this->assertSame('2026-01-01', $account->import_cutoff_date->toDateString());
    }

    public function test_refuses_non_base_currency_on_budget(): void
    {
        $account = $this->seedAccount(currency: 'RON');

        $this->artisan('spendula:accounts:seed-mock', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => '79f0ce5c-5cff-40dd-8560-363caf59b878',
        ])
            ->expectsOutputToContain('Refusing to map non-base-currency account')
            ->assertFailed();

        $account->refresh();
        $this->assertNull($account->ynab_account_id);
    }

    public function test_allows_non_base_currency_to_tracking(): void
    {
        $account = $this->seedAccount(currency: 'RON');

        $this->artisan('spendula:accounts:seed-mock', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            '--ynab-account-type' => 'tracking',
        ])->assertSuccessful();

        $account->refresh();
        $this->assertSame(YnabAccountType::Tracking, $account->ynab_account_type);
    }

    public function test_missing_bank_account_fails(): void
    {
        $this->artisan('spendula:accounts:seed-mock', [
            '--bank-account-id' => '00000000-0000-0000-0000-000000000000',
            '--ynab-account-id' => '79f0ce5c-5cff-40dd-8560-363caf59b878',
        ])
            ->expectsOutputToContain('No bank_account with id')
            ->assertFailed();
    }

    public function test_defaults_import_cutoff_to_today(): void
    {
        $account = $this->seedAccount(currency: 'EUR');

        $this->artisan('spendula:accounts:seed-mock', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => '79f0ce5c-5cff-40dd-8560-363caf59b878',
        ])->assertSuccessful();

        $account->refresh();
        $this->assertSame(Carbon::today()->toDateString(), $account->import_cutoff_date->toDateString());
    }

    private function seedAccount(string $currency): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => $currency,
            'is_base_currency' => strtoupper($currency) === 'EUR',
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }
}
