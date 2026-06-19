<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CounterpartyRecomputeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-29 12:00:00'));

        Bank::query()->create([
            'slug' => 'mock',
            'display_name' => 'Mock',
            'aspsp_name' => 'Mock',
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

    public function test_recomputes_counterparty_name_and_level_from_raw_payload(): void
    {
        $tx = $this->seedTransaction(
            counterpartyName: 'Stale Old Value',
            level: 2,
            rawPayload: [
                'credit_debit_indicator' => 'CRDT',
                'debtor' => ['name' => 'Direct Counterparty'],
                'remittance_information' => ['ignored when L0 hits'],
            ],
        );

        $this->artisan('spendula:counterparty:recompute')->assertSuccessful();

        $tx->refresh();
        $this->assertSame('Direct Counterparty', $tx->counterparty_name);
        $this->assertSame(0, $tx->counterparty_resolution_level);
    }

    public function test_dry_run_does_not_write(): void
    {
        $tx = $this->seedTransaction(
            counterpartyName: 'Stale Old Value',
            level: 2,
            rawPayload: [
                'credit_debit_indicator' => 'CRDT',
                'debtor' => ['name' => 'Should Have Won'],
            ],
        );

        $this->artisan('spendula:counterparty:recompute', ['--dry-run' => true])
            ->expectsOutputToContain('--dry-run set: no rows written.')
            ->assertSuccessful();

        $tx->refresh();
        $this->assertSame('Stale Old Value', $tx->counterparty_name);
        $this->assertSame(2, $tx->counterparty_resolution_level);
    }

    public function test_bank_filter_scopes_recompute(): void
    {
        $mockTx = $this->seedTransaction(
            counterpartyName: 'old name',
            level: 2,
            rawPayload: ['credit_debit_indicator' => 'CRDT', 'debtor' => ['name' => 'Updated']],
        );

        // Second bank with a stale row that should NOT be touched.
        Bank::query()->create([
            'slug' => 'other',
            'display_name' => 'Other',
            'aspsp_name' => 'Other',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);
        $otherAccount = $this->seedBankAccount('other');
        $otherTx = $this->seedTransactionFor($otherAccount, 'old', 2, [
            'credit_debit_indicator' => 'CRDT',
            'debtor' => ['name' => 'Untouched'],
        ]);

        $this->artisan('spendula:counterparty:recompute', ['--bank' => 'mock'])
            ->expectsOutputToContain('Scanned 1 transaction(s) for bank=\'mock\'')
            ->assertSuccessful();

        $this->assertSame('Updated', $mockTx->fresh()->counterparty_name);
        $this->assertSame('old', $otherTx->fresh()->counterparty_name);
    }

    public function test_unknown_bank_slug_fails(): void
    {
        $this->artisan('spendula:counterparty:recompute', ['--bank' => 'no-such-bank'])
            ->expectsOutputToContain("No bank_accounts for slug 'no-such-bank'")
            ->assertFailed();
    }

    // -----------------------------------------------------------------------
    // Own-account backfill tests (GH #14)
    // -----------------------------------------------------------------------

    /**
     * Acceptance criterion #7: fetched same-currency own-account rows promoted to transfer.
     */
    public function test_recompute_promotes_fetched_own_account_same_currency_to_transfer_status(): void
    {
        $source = $this->seedBankAccount('mock');
        $dest = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL EUR Dest',
            'iban' => 'RO00BANK0000000000000050',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $tx = $this->seedTransactionFor($source, 'ACME SRL', 2, [
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => null,
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO00BANK0000000000000050, Details, transfer',
            ],
        ]);

        $this->artisan('spendula:counterparty:recompute')->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Transfer, $tx->status);
        $this->assertSame('Transfer : ING SRL EUR Dest', $tx->counterparty_name);
        $this->assertNotNull($dest->id);
    }

    /**
     * Acceptance criterion #7: approved rows are never status-mutated during backfill.
     */
    public function test_recompute_does_not_mutate_approved_own_account_row(): void
    {
        $source = $this->seedBankAccount('mock');

        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL EUR Dest',
            'iban' => 'RO00BANK0000000000000051',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $tx = $this->seedTransactionFor($source, 'Old Name', 2, [
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => null,
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO00BANK0000000000000051, Details, transfer',
            ],
        ], TransactionStatus::Approved);

        $this->artisan('spendula:counterparty:recompute')->assertSuccessful();

        $tx->refresh();
        // Name MAY be updated (counterparty resolution still runs); status must not change.
        $this->assertSame(TransactionStatus::Approved, $tx->status);
    }

    /**
     * Acceptance criterion #7: --dry-run honours the own-account promotion path (no writes).
     */
    public function test_recompute_dry_run_does_not_write_own_account_promotion(): void
    {
        $source = $this->seedBankAccount('mock');

        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL EUR Dest',
            'iban' => 'RO00BANK0000000000000052',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $tx = $this->seedTransactionFor($source, 'ACME SRL', 2, [
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => null,
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO00BANK0000000000000052, Details, transfer',
            ],
        ]);

        $this->artisan('spendula:counterparty:recompute', ['--dry-run' => true])
            ->expectsOutputToContain('--dry-run set: no rows written.')
            ->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Fetched, $tx->status);
        $this->assertSame('ACME SRL', $tx->counterparty_name);
    }

    /**
     * Acceptance criterion #7 (FX variant): fetched cross-currency own-account rows are
     * also promoted to transfer during backfill (see DECISIONS.md — GH #14 FX-as-transfer
     * reversal). Previously these stayed fetched with counterparty_name="Currency Exchange".
     */
    public function test_recompute_promotes_fetched_fx_own_account_to_transfer_status(): void
    {
        $source = $this->seedBankAccount('mock');

        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL RON',
            'iban' => 'RO00BANK0000000000000053',
            'currency' => 'RON',
            'is_base_currency' => false,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $tx = $this->seedTransactionFor($source, 'Currency Exchange', 2, [
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => null,
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO00BANK0000000000000053, Details, schimb valutar',
            ],
        ]);

        $this->artisan('spendula:counterparty:recompute')->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Transfer, $tx->status);
        $this->assertSame('Transfer : ING SRL RON', $tx->counterparty_name);
        $this->assertNotSame('Currency Exchange', $tx->counterparty_name);
    }

    /** @param  array<string, mixed>  $rawPayload */
    private function seedTransaction(string $counterpartyName, int $level, array $rawPayload): Transaction
    {
        return $this->seedTransactionFor($this->seedBankAccount('mock'), $counterpartyName, $level, $rawPayload);
    }

    /** @param  array<string, mixed>  $rawPayload */
    private function seedTransactionFor(
        BankAccount $account,
        string $counterpartyName,
        int $level,
        array $rawPayload,
        TransactionStatus $status = TransactionStatus::Fetched,
    ): Transaction {
        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -10_000,
            'currency' => 'EUR',
            'credit_debit_indicator' => $rawPayload['credit_debit_indicator'] ?? 'DBIT',
            'counterparty_name' => $counterpartyName,
            'counterparty_resolution_level' => $level,
            'remittance_information' => $rawPayload['remittance_information'][0] ?? null,
            'raw_payload' => $rawPayload,
            'dedup_hash' => str_pad(bin2hex(random_bytes(16)), 32, '0'),
            'occurrence' => 1,
            'status' => $status->value,
            'transaction_status' => 'BOOK',
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }

    private function seedBankAccount(string $slug): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => $slug,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }
}
