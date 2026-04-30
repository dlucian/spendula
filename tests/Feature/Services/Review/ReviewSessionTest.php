<?php

namespace Tests\Feature\Services\Review;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Defends the structural exclusion of `status = tracking` rows from the
 * review queue (SPEC §5.3 / §6.5). ReviewSession filters by
 * `status = fetched`, so tracking rows are dropped by construction; this
 * test guards against an accidental relaxation of that filter.
 *
 * Runs against the non-TTY branch of `ReviewSession::run()`
 * (`app()->runningUnitTests()` short-circuits the raw-mode loop) and asserts
 * on the `{N} transaction(s) awaiting review` warning, which reflects the
 * size of the loaded queue.
 */
class ReviewSessionTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $account;

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
            'ynab_account_type' => YnabAccountType::OnBudget,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    public function test_queue_excludes_tracking_status_rows(): void
    {
        $this->seedTransaction('ref-fetched', TransactionStatus::Fetched);
        $this->seedTransaction('ref-tracking', TransactionStatus::Tracking);

        $this->artisan('spendula:review')
            ->expectsOutputToContain('1 transaction(s) awaiting review')
            ->doesntExpectOutput('2 transaction(s) awaiting review')
            ->assertSuccessful();

        // Both rows untouched: review never approves/skips/transfers in a
        // non-TTY run, but the assertion is here to keep the test honest if
        // the structural exclusion ever flips.
        $this->assertSame(
            TransactionStatus::Tracking,
            Transaction::query()->where('entry_reference', 'ref-tracking')->sole()->status,
        );
        $this->assertSame(
            TransactionStatus::Fetched,
            Transaction::query()->where('entry_reference', 'ref-fetched')->sole()->status,
        );
    }

    private function seedTransaction(string $entryRef, TransactionStatus $status): Transaction
    {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $this->account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => $entryRef,
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => 'Coffee Shop',
            'counterparty_resolution_level' => 0,
            'occurrence' => 1,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
