<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\PayeeRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesDeleteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_removes_existing_rule(): void
    {
        $this->seedBank('mock');
        $rule = PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        $this->artisan('spendula:rules:delete', ['id' => $rule->id])
            ->expectsOutputToContain("Deleted rule 'mock → Spotify'")
            ->assertSuccessful();

        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_delete_unknown_id_fails_with_clear_message(): void
    {
        $this->artisan('spendula:rules:delete', ['id' => '00000000-0000-0000-0000-000000000000'])
            ->expectsOutputToContain('No rule found')
            ->assertFailed();
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
