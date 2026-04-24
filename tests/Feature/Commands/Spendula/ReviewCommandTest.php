<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReviewCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_approve_trivial_flag_applies_and_reports_count(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        // Two trivial, one level-2 (not trivial).
        $this->seedTransaction($account, level: 0, entryRef: 'ref-trivial-a');
        $this->seedTransaction($account, level: 1, entryRef: 'ref-trivial-b');
        $nonTrivial = $this->seedTransaction($account, level: 2, entryRef: 'ref-nontrivial');

        $this->artisan('spendula:review', ['--bulk-approve-trivial' => true])
            ->expectsOutputToContain('Bulk-approved 2 trivial transaction(s) in EUR.')
            ->assertSuccessful();

        $this->assertSame(2, Transaction::query()->where('status', TransactionStatus::Approved->value)->count());
        $this->assertSame(TransactionStatus::Fetched, $nonTrivial->refresh()->status);
    }

    private function seedMockBank(): void
    {
        Bank::query()->create([
            'slug' => 'mock',
            'display_name' => 'Mock',
            'aspsp_name' => 'Mock ASPSP',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);
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

    private function seedTransaction(BankAccount $account, int $level, string $entryRef): Transaction
    {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => $entryRef,
            'status' => TransactionStatus::Fetched,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => $account->currency,
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_resolution_level' => $level,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
