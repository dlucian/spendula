<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\PayeeRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesAddCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_creates_clean_rule(): void
    {
        $this->seedBank('mock');

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => 'approve',
        ])
            ->expectsOutputToContain('Rule added:')
            ->assertSuccessful();

        $this->assertSame(1, PayeeRule::query()->count());
        $rule = PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail();
        $this->assertSame('mock', $rule->bank_slug);
        $this->assertSame(TransactionStatus::Approved, $rule->action);
        $this->assertNull($rule->skip_reason);
    }

    public function test_add_skip_with_reason_persists_skip_reason(): void
    {
        $this->seedBank('mock');

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'Random Charity',
            'action' => 'skip',
            '--reason' => 'one-off donation',
        ])
            ->expectsOutputToContain('Rule added:')
            ->assertSuccessful();

        $rule = PayeeRule::query()->where('counterparty_name', 'Random Charity')->firstOrFail();
        $this->assertSame(TransactionStatus::Skipped, $rule->action);
        $this->assertSame('one-off donation', $rule->skip_reason);
    }

    public function test_add_rejects_reason_on_non_skip_action(): void
    {
        $this->seedBank('mock');

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => 'approve',
            '--reason' => 'x',
        ])
            ->expectsOutputToContain('skip')
            ->assertFailed();

        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_add_refuses_denylisted_payee(): void
    {
        $this->seedBank('mock');

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'REVOLUT',
            'action' => 'approve',
        ])
            ->expectsOutputToContain('denylist')
            ->assertFailed();

        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_add_refuses_when_rule_exists_without_force(): void
    {
        $this->seedBank('mock');
        $existing = PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        // Single combined substring works around a Mockery quirk: each
        // expectsOutputToContain registers its own withArgs expectation,
        // and a single doWrite call only fulfils one of them.
        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => 'skip',
        ])
            ->expectsOutputToContain("{$existing->id}), use --force")
            ->assertFailed();

        $this->assertSame(1, PayeeRule::query()->count());
        $this->assertSame(TransactionStatus::Approved, $existing->fresh()->action);
    }

    public function test_add_with_force_overwrites_action_and_skip_reason(): void
    {
        $this->seedBank('mock');
        $rule = PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Skipped->value,
            'skip_reason' => 'old',
        ]);

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => 'approve',
            '--force' => true,
        ])
            ->expectsOutputToContain('Rule added:')
            ->assertSuccessful();

        $this->assertSame(1, PayeeRule::query()->count());
        $rule->refresh();
        $this->assertSame($rule->id, PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail()->id);
        $this->assertSame(TransactionStatus::Approved, $rule->action);
        $this->assertNull($rule->skip_reason);
    }

    public function test_add_rejects_unknown_bank_slug(): void
    {
        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'nonexistent',
            'counterparty_name' => 'Spotify',
            'action' => 'approve',
        ])
            ->expectsOutputToContain('not found')
            ->assertFailed();

        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_add_rejects_unknown_action(): void
    {
        $this->seedBank('mock');

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => 'weird',
        ])
            ->expectsOutputToContain('approve')
            ->assertFailed();

        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_add_rejects_blank_counterparty_name(): void
    {
        $this->seedBank('mock');

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => '   ',
            'action' => 'approve',
        ])
            ->expectsOutputToContain('blank')
            ->assertFailed();

        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_add_respects_operator_name_denylist(): void
    {
        $this->seedBank('mock');
        config()->set('spendula.payee_rule_guards.operator_names', ['Jane Doe']);

        $this->artisan('spendula:rules:add', [
            'bank_slug' => 'mock',
            'counterparty_name' => 'Jane Doe',
            'action' => 'approve',
        ])
            ->expectsOutputToContain('denylist')
            ->assertFailed();

        $this->assertSame(0, PayeeRule::query()->count());
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
