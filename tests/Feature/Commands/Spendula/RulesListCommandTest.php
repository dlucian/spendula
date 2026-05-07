<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\PayeeRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesListCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_prints_helpful_message_when_no_rules(): void
    {
        $this->artisan('spendula:rules:list')
            ->expectsOutputToContain('No payee rules recorded yet.')
            ->assertSuccessful();
    }

    public function test_list_prints_existing_rules(): void
    {
        $this->seedBank('mock');
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        // Single combined substring works around a Mockery quirk: each
        // expectsOutputToContain registers its own withArgs expectation,
        // and a single doWrite call only fulfils one of them. Asserting
        // bank+name+action together against one line is more direct anyway.
        $this->artisan('spendula:rules:list')
            ->expectsOutputToContain('mock  Spotify  approved')
            ->assertSuccessful();
    }

    public function test_list_filters_by_bank(): void
    {
        $this->seedBank('mock');
        $this->seedBank('other');
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);
        PayeeRule::query()->create([
            'bank_slug' => 'other',
            'counterparty_name' => 'Netflix',
            'action' => TransactionStatus::Approved->value,
        ]);

        $this->artisan('spendula:rules:list', ['--bank' => 'mock'])
            ->expectsOutputToContain('mock  Spotify  approved')
            ->doesntExpectOutputToContain('Netflix')
            ->assertSuccessful();
    }

    public function test_list_with_unknown_bank_filter_prints_helpful_message(): void
    {
        $this->artisan('spendula:rules:list', ['--bank' => 'nonexistent'])
            ->expectsOutputToContain("No payee rules for bank 'nonexistent'.")
            ->assertSuccessful();
    }

    private function seedBank(string $slug): void
    {
        Bank::query()->create([
            'slug' => $slug,
            'display_name' => ucfirst($slug),
            'aspsp_name' => ucfirst($slug).' ASPSP',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);
    }
}
