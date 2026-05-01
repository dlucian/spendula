<?php

namespace Tests\Feature\Services\Review;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Review\TransactionActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TransactionActionsTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $account;

    private TransactionActions $actions;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->account = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $this->actions = new TransactionActions;
    }

    public function test_approve_transitions_fetched_to_approved_and_clears_skip_metadata(): void
    {
        $tx = $this->fetchedTransaction();
        $tx->skipped_at = Carbon::now();
        $tx->skip_reason = 'stale';
        $tx->save();

        $this->actions->approve($tx);

        $tx->refresh();
        $this->assertSame(TransactionStatus::Approved, $tx->status);
        $this->assertNull($tx->skipped_at);
        $this->assertNull($tx->skip_reason);
    }

    public function test_skip_sets_reason_when_provided(): void
    {
        $tx = $this->fetchedTransaction();

        $this->actions->skip($tx, 'duplicate from manual import');

        $tx->refresh();
        $this->assertSame(TransactionStatus::Skipped, $tx->status);
        $this->assertSame('duplicate from manual import', $tx->skip_reason);
        $this->assertNotNull($tx->skipped_at);
    }

    public function test_skip_allows_empty_reason(): void
    {
        $tx = $this->fetchedTransaction();

        $this->actions->skip($tx, '');

        $tx->refresh();
        $this->assertSame(TransactionStatus::Skipped, $tx->status);
        $this->assertNull($tx->skip_reason);
    }

    public function test_mark_transfer_transitions_to_transfer(): void
    {
        $tx = $this->fetchedTransaction();

        $this->actions->markTransfer($tx);

        $tx->refresh();
        $this->assertSame(TransactionStatus::Transfer, $tx->status);
    }

    public function test_revert_to_fetched_from_approved_clears_skip_metadata(): void
    {
        $tx = $this->fetchedTransaction();
        $this->actions->approve($tx);
        $tx->refresh();
        $this->assertSame(TransactionStatus::Approved, $tx->status);

        $this->actions->revertToFetched($tx);

        $tx->refresh();
        $this->assertSame(TransactionStatus::Fetched, $tx->status);
        $this->assertNull($tx->skipped_at);
        $this->assertNull($tx->skip_reason);
    }

    public function test_revert_to_fetched_from_skipped_clears_reason_and_skipped_at(): void
    {
        $tx = $this->fetchedTransaction();
        $this->actions->skip($tx, 'wrong reason');
        $tx->refresh();
        $this->assertSame(TransactionStatus::Skipped, $tx->status);
        $this->assertSame('wrong reason', $tx->skip_reason);
        $this->assertNotNull($tx->skipped_at);

        $this->actions->revertToFetched($tx);

        $tx->refresh();
        $this->assertSame(TransactionStatus::Fetched, $tx->status);
        $this->assertNull($tx->skipped_at);
        $this->assertNull($tx->skip_reason);
    }

    public function test_revert_to_fetched_from_transfer(): void
    {
        $tx = $this->fetchedTransaction();
        $this->actions->markTransfer($tx);
        $tx->refresh();
        $this->assertSame(TransactionStatus::Transfer, $tx->status);

        $this->actions->revertToFetched($tx);

        $tx->refresh();
        $this->assertSame(TransactionStatus::Fetched, $tx->status);
        $this->assertNull($tx->skipped_at);
        $this->assertNull($tx->skip_reason);
    }

    public function test_revert_to_fetched_is_idempotent_on_already_fetched(): void
    {
        $tx = $this->fetchedTransaction();
        $this->assertSame(TransactionStatus::Fetched, $tx->status);

        $this->actions->revertToFetched($tx);

        $tx->refresh();
        $this->assertSame(TransactionStatus::Fetched, $tx->status);
        $this->assertNull($tx->skipped_at);
        $this->assertNull($tx->skip_reason);
    }

    public function test_bulk_approve_trivial_only_touches_level_0_and_1_base_currency_fetched(): void
    {
        $trivial = $this->fetchedTransaction(level: 0, currency: 'EUR');
        $trivial1 = $this->fetchedTransaction(level: 1, currency: 'EUR', entryRef: 'ref-trivial-1');
        $notTrivialLevel = $this->fetchedTransaction(level: 2, currency: 'EUR', entryRef: 'ref-lvl2');
        $notBaseCurrency = $this->fetchedTransaction(level: 0, currency: 'RON', entryRef: 'ref-ron');
        $notFetched = $this->fetchedTransaction(level: 0, currency: 'EUR', entryRef: 'ref-approved');
        $notFetched->status = TransactionStatus::Approved;
        $notFetched->save();

        $count = $this->actions->bulkApproveTrivial('EUR');

        $this->assertSame(2, $count);
        $this->assertSame(TransactionStatus::Approved, $trivial->refresh()->status);
        $this->assertSame(TransactionStatus::Approved, $trivial1->refresh()->status);
        $this->assertSame(TransactionStatus::Fetched, $notTrivialLevel->refresh()->status);
        $this->assertSame(TransactionStatus::Fetched, $notBaseCurrency->refresh()->status);
        $this->assertSame(TransactionStatus::Approved, $notFetched->refresh()->status);
    }

    private function fetchedTransaction(int $level = 0, string $currency = 'EUR', string $entryRef = 'ref-1'): Transaction
    {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $this->account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => $entryRef,
            'status' => TransactionStatus::Fetched,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => $currency,
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_resolution_level' => $level,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
