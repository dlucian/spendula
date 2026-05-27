<?php

namespace Tests\Feature\Push;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Sync\DedupHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PushRunnerInactiveAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-19 12:00:00'));

        config()->set('spendula.ynab.base_url', 'https://api.ynab.test/v1');
        config()->set('spendula.ynab.access_token', 'test-token');
        config()->set('spendula.ynab.plan_id', 'plan-under-test');

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inactive_account_approved_rows_not_pushed_to_ynab(): void
    {
        $inactive = $this->seedAccount(active: false);
        $tx = $this->seedApprovedTransaction($inactive);

        Http::fake();

        $this->artisan('spendula:push')->assertSuccessful();

        Http::assertNothingSent();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Approved, $tx->status);
        $this->assertNull($tx->pushed_at);
    }

    public function test_active_account_in_same_run_still_pushes(): void
    {
        $inactive = $this->seedAccount(active: false);
        $deadTx = $this->seedApprovedTransaction($inactive);

        $active = $this->seedAccount(active: true);
        $liveTx = $this->seedApprovedTransaction($active);

        $liveImportId = $this->expectedImportId($liveTx);

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([
                'data' => [
                    'transactions' => [['id' => 'ynab-live', 'import_id' => $liveImportId]],
                    'duplicate_import_ids' => [],
                ],
            ], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();

        Http::assertSentCount(1);

        $deadTx->refresh();
        $this->assertSame(TransactionStatus::Approved, $deadTx->status, 'Inactive account row must stay approved.');
        $this->assertNull($deadTx->pushed_at);

        $liveTx->refresh();
        $this->assertSame(TransactionStatus::Pushed, $liveTx->status, 'Active account row must be pushed.');
    }

    private function expectedImportId(Transaction $tx): string
    {
        return DedupHasher::importId(
            bankAccountId: $tx->bank_account_id,
            bookingDate: $tx->booking_date->toDateString(),
            amountMilliunits: $tx->amount_milliunits,
            rawCounterparty: (string) $tx->counterparty_name,
            occurrence: $tx->occurrence,
            entryReference: $tx->entry_reference,
        );
    }

    private function seedAccount(bool $active): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => $active,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'ynab_account_id' => (string) Str::uuid(),
            'ynab_account_type' => YnabAccountType::OnBudget,
            'import_cutoff_date' => Carbon::parse('2026-01-01'),
        ]);
    }

    private function seedApprovedTransaction(BankAccount $account): Transaction
    {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => 'ref-'.$seq,
            'status' => TransactionStatus::Approved,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -3450,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => 'Pingo Doce',
            'counterparty_resolution_level' => 0,
            'occurrence' => 1,
            'push_attempt_count' => 0,
            'raw_payload' => [
                'credit_debit_indicator' => 'DBIT',
                'creditor' => ['name' => 'Pingo Doce'],
                'debtor' => null,
            ],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
