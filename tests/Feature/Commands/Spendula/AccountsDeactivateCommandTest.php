<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankAccountIdentifier;
use App\Models\BankAccountSession;
use App\Models\BankAccountSyncState;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountsDeactivateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-19 12:00:00'));

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scripted_deactivate_flips_active_and_exits_zero(): void
    {
        $account = $this->seedAccount(currency: 'EUR', active: true);

        $this->artisan('spendula:accounts:deactivate', ['--id' => $account->id])
            ->expectsConfirmation('Deactivate this account? Sync will stop attempting it. (y/N)', 'yes')
            ->expectsOutputToContain('accounts_deactivated=1')
            ->assertSuccessful();

        $this->assertFalse($account->fresh()->active);
    }

    public function test_refuses_malformed_uuid(): void
    {
        $this->artisan('spendula:accounts:deactivate', ['--id' => 'does-not-exist'])
            ->expectsOutputToContain('--id is not a valid UUID: does-not-exist')
            ->assertFailed();
    }

    public function test_refuses_unknown_valid_uuid(): void
    {
        $this->artisan('spendula:accounts:deactivate', ['--id' => '00000000-0000-0000-0000-000000000000'])
            ->expectsOutputToContain('No bank_account with id=00000000-0000-0000-0000-000000000000')
            ->assertFailed();
    }

    public function test_refuses_already_inactive_account(): void
    {
        $account = $this->seedAccount(currency: 'EUR', active: false);

        $this->artisan('spendula:accounts:deactivate', ['--id' => $account->id])
            ->expectsOutputToContain("bank_account {$account->id} is already inactive")
            ->assertFailed();

        $this->assertFalse($account->fresh()->active, 'Row must remain inactive.');
    }

    public function test_refuses_when_unpushed_rows_present_without_force(): void
    {
        $account = $this->seedAccount(currency: 'EUR', active: true);
        $this->seedTransaction($account, TransactionStatus::Approved);
        $this->seedTransaction($account, TransactionStatus::Transfer);

        $this->artisan('spendula:accounts:deactivate', ['--id' => $account->id])
            ->expectsOutputToContain('those rows will become dead')
            ->assertFailed();

        $this->assertTrue($account->fresh()->active, 'Account must still be active.');
    }

    public function test_force_bypasses_unpushed_refusal_and_warns_in_confirm(): void
    {
        $account = $this->seedAccount(currency: 'EUR', active: true);
        $this->seedTransaction($account, TransactionStatus::Approved);

        $expectedPrompt = 'Deactivate this account? 1 approved/transfer transaction(s) will become DEAD: '
            .'not pushable, not visible in spendula:status, until the account is reactivated. (y/N)';

        $this->artisan('spendula:accounts:deactivate', ['--id' => $account->id, '--force' => true])
            ->expectsConfirmation($expectedPrompt, 'yes')
            ->expectsOutputToContain('accounts_deactivated=1')
            ->assertSuccessful();

        $this->assertFalse($account->fresh()->active);
    }

    public function test_untouched_sibling_tables(): void
    {
        $account = $this->seedAccount(currency: 'EUR', active: true);
        $this->seedTransaction($account, TransactionStatus::Fetched);

        $sessionsBefore = BankAccountSession::query()->where('bank_account_id', $account->id)->count();
        $syncStateBefore = BankAccountSyncState::query()->where('bank_account_id', $account->id)->count();
        $identifiersBefore = BankAccountIdentifier::query()->where('bank_account_id', $account->id)->count();
        $txBefore = Transaction::query()->where('bank_account_id', $account->id)->count();

        $this->artisan('spendula:accounts:deactivate', ['--id' => $account->id])
            ->expectsConfirmation('Deactivate this account? Sync will stop attempting it. (y/N)', 'yes')
            ->assertSuccessful();

        $this->assertSame($sessionsBefore, BankAccountSession::query()->where('bank_account_id', $account->id)->count());
        $this->assertSame($syncStateBefore, BankAccountSyncState::query()->where('bank_account_id', $account->id)->count());
        $this->assertSame($identifiersBefore, BankAccountIdentifier::query()->where('bank_account_id', $account->id)->count());
        $this->assertSame($txBefore, Transaction::query()->where('bank_account_id', $account->id)->count());
    }

    public function test_declined_confirm_exits_one_without_mutating(): void
    {
        $account = $this->seedAccount(currency: 'EUR', active: true);

        $this->artisan('spendula:accounts:deactivate', ['--id' => $account->id])
            ->expectsConfirmation('Deactivate this account? Sync will stop attempting it. (y/N)', 'no')
            ->expectsOutputToContain('Aborted.')
            ->assertFailed();

        $this->assertTrue($account->fresh()->active);
    }

    public function test_interactive_picker_lists_only_active_accounts(): void
    {
        $active = $this->seedAccount(currency: 'EUR', iban: 'IBAN-ACTIVE', active: true);
        $inactive = $this->seedAccount(currency: 'EUR', iban: 'IBAN-INACTIVE', active: false);

        $expectedLabel = "mock EUR IBAN-ACTIVE [{$active->id}]";
        $unexpectedLabel = "mock EUR IBAN-INACTIVE [{$inactive->id}]";

        $this->artisan('spendula:accounts:deactivate')
            ->expectsChoice(
                'Pick an account to deactivate',
                $expectedLabel,
                [$expectedLabel, '[cancel]'],
            )
            ->expectsConfirmation('Deactivate this account? Sync will stop attempting it. (y/N)', 'yes')
            ->expectsOutputToContain('accounts_deactivated=1')
            ->assertSuccessful();

        $this->assertFalse($active->fresh()->active);
        // Inactive account seeded as active=false; it must remain untouched (still false).
        $this->assertFalse($inactive->fresh()->active);
    }

    public function test_interactive_picker_uniqueness_safe_with_same_bank_currency_null_iban(): void
    {
        // Two active accounts with same bank_slug, same currency, both iban=null.
        // The label must be unique via the full UUID so the picker can address the second row.
        $first = $this->seedAccount(currency: 'EUR', iban: null, active: true);
        $second = $this->seedAccount(currency: 'EUR', iban: null, active: true);

        $secondLabel = "mock EUR (no IBAN) [{$second->id}]";

        $this->artisan('spendula:accounts:deactivate')
            ->expectsChoice(
                'Pick an account to deactivate',
                $secondLabel,
                [
                    "mock EUR (no IBAN) [{$first->id}]",
                    $secondLabel,
                    '[cancel]',
                ],
            )
            ->expectsConfirmation('Deactivate this account? Sync will stop attempting it. (y/N)', 'yes')
            ->expectsOutputToContain('accounts_deactivated=1')
            ->assertSuccessful();

        $this->assertTrue($first->fresh()->active, 'First account must be untouched.');
        $this->assertFalse($second->fresh()->active, 'Second account must be deactivated.');
    }

    public function test_interactive_picker_cancel_exits_successfully_without_mutation(): void
    {
        $account = $this->seedAccount(currency: 'EUR', active: true);

        $this->artisan('spendula:accounts:deactivate')
            ->expectsChoice(
                'Pick an account to deactivate',
                '[cancel]',
                ["mock EUR (no IBAN) [{$account->id}]", '[cancel]'],
            )
            ->expectsOutputToContain('Cancelled.')
            ->assertSuccessful();

        $this->assertTrue($account->fresh()->active);
    }

    public function test_no_active_accounts_interactive_exits_cleanly(): void
    {
        $this->artisan('spendula:accounts:deactivate')
            ->expectsOutputToContain('No active accounts.')
            ->assertSuccessful();
    }

    public function test_no_interaction_without_id_fails(): void
    {
        $this->seedAccount(currency: 'EUR', active: true);

        $this->artisan('spendula:accounts:deactivate', ['--no-interaction' => true])
            ->expectsOutputToContain('No TTY available')
            ->assertFailed();
    }

    private function seedAccount(string $currency, ?string $iban = null, bool $active = true): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => 'mock',
            'iban' => $iban,
            'currency' => $currency,
            'is_base_currency' => strtoupper($currency) === 'EUR',
            'active' => $active,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    private function seedTransaction(BankAccount $account, TransactionStatus $status): Transaction
    {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => Carbon::now()->subDay()->toDateString(),
            'amount_milliunits' => -1000,
            'currency' => $account->currency,
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => 'Counterparty',
            'counterparty_resolution_level' => 0,
            'raw_payload' => ['stub' => true],
            'occurrence' => 1,
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
