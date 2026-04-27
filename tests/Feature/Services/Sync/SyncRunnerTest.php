<?php

namespace Tests\Feature\Services\Sync;

use App\Enums\BankConnectionStatus;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankAccountSession;
use App\Models\BankAccountSyncState;
use App\Models\BankConnection;
use App\Models\SyncRun;
use App\Models\Transaction;
use App\Services\EnableBanking\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('spendula.enable_banking.base_url', 'https://api.enablebanking.test');
        $this->app->bind(Jwt::class, fn () => new class('app', 'key') extends Jwt
        {
            public function sign(int $ttlSeconds = 3600): string
            {
                return 'stub';
            }
        });

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

    public function test_first_run_inserts_all_transactions_second_run_is_a_no_op(): void
    {
        $this->seedConnectionWithAccount('uid-eur');

        $page = ['transactions' => [
            $this->eurTransaction('ref-1'),
            $this->eurTransaction('ref-2', '2.50', 'CRDT', 'Employer'),
        ], 'continuation_key' => null];

        Http::fake([
            'https://api.enablebanking.test/accounts/uid-eur/transactions*' => Http::response($page, 200),
        ]);

        $this->artisan('spendula:sync')->assertSuccessful();

        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(2, Transaction::query()->where('status', TransactionStatus::Fetched->value)->count());

        $run1 = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $run1->transactions_inserted);
        $this->assertSame(0, $run1->transactions_updated);
        $this->assertSame(0, $run1->transactions_deduped);
        $this->assertSame(0, $run1->error_count);

        $this->artisan('spendula:sync')->assertSuccessful();

        $this->assertSame(2, Transaction::query()->count(), 'Re-running sync must not insert duplicates.');

        $run2 = SyncRun::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $run2->transactions_inserted);
        $this->assertSame(2, $run2->transactions_deduped);
    }

    public function test_pagination_persists_between_pages_and_clears_continuation_key_at_end(): void
    {
        $account = $this->seedConnectionWithAccount('uid-eur');

        // Realistic-shape EB continuation key: base64(json) + '.' + 64-char hex signature.
        // Total ~350 chars — exceeds varchar(255), regression guard for the schema.
        $longContinuationKey = base64_encode(str_repeat('paging-cursor-payload-', 12))
            .'.'.str_repeat('a1b2c3d4', 8);

        Http::fake([
            'https://api.enablebanking.test/accounts/uid-eur/transactions*' => Http::sequence()
                ->push([
                    'transactions' => [$this->eurTransaction('ref-page-1')],
                    'continuation_key' => $longContinuationKey,
                ], 200)
                ->push([
                    'transactions' => [$this->eurTransaction('ref-page-2', bookingDate: '2026-04-16')],
                    'continuation_key' => null,
                ], 200),
        ]);

        $this->artisan('spendula:sync')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(2, Transaction::query()->count());

        $syncState = BankAccountSyncState::query()->findOrFail($account->id);
        $this->assertNull($syncState->last_continuation_key, 'Continuation key must clear on clean finish.');
        $this->assertNotNull($syncState->last_successful_sync_at);
        $this->assertNotNull($syncState->last_fetched_through);
    }

    public function test_rate_limit_persists_continuation_key_and_continues_with_other_accounts(): void
    {
        $eurAccount = $this->seedConnectionWithAccount('uid-eur');
        $ronAccount = $this->addSecondAccount('uid-ron', 'RON');

        Http::fake([
            'https://api.enablebanking.test/accounts/uid-eur/transactions*' => Http::sequence()
                ->push([
                    'transactions' => [$this->eurTransaction('ref-page-1')],
                    'continuation_key' => 'page-2-token',
                ], 200)
                ->push(['error' => 'rate_limit'], 429),
            'https://api.enablebanking.test/accounts/uid-ron/transactions*' => Http::response([
                'transactions' => [$this->eurTransaction('ref-ron')],
                'continuation_key' => null,
            ], 200),
        ]);

        $this->artisan('spendula:sync')->assertFailed();

        $eurState = BankAccountSyncState::query()->findOrFail($eurAccount->id);
        $this->assertSame('page-2-token', $eurState->last_continuation_key);
        $this->assertGreaterThan(0, $eurState->consecutive_failure_count);

        $ronState = BankAccountSyncState::query()->findOrFail($ronAccount->id);
        $this->assertNull($ronState->last_continuation_key, 'RON account succeeded — no resume state.');

        // sync_run_errors has exactly one rate_limit entry.
        $this->assertSame(1, SyncRun::query()->sole()->errors()->where('error_type', 'rate_limit')->count());
    }

    public function test_resumes_from_last_continuation_key(): void
    {
        $account = $this->seedConnectionWithAccount('uid-eur');

        // Pre-seed an interrupted state.
        $state = BankAccountSyncState::query()->firstOrCreate(
            ['bank_account_id' => $account->id],
            ['consecutive_failure_count' => 0],
        );
        $state->last_continuation_key = 'resume-from-here';
        $state->last_fetched_through = Carbon::parse('2026-04-10');
        $state->save();

        Http::fake([
            'https://api.enablebanking.test/accounts/uid-eur/transactions*' => Http::response([
                'transactions' => [$this->eurTransaction('ref-after-resume')],
                'continuation_key' => null,
            ], 200),
        ]);

        $this->artisan('spendula:sync')->assertSuccessful();

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'continuation_key=resume-from-here'));
    }

    public function test_consent_revoked_marks_connection_revoked(): void
    {
        $this->seedConnectionWithAccount('uid-eur');

        Http::fake([
            'https://api.enablebanking.test/accounts/uid-eur/transactions*' => Http::response(['error' => 'revoked'], 403),
        ]);

        $this->artisan('spendula:sync')->assertFailed();

        $connection = BankConnection::query()->sole();
        $this->assertSame(BankConnectionStatus::Revoked, $connection->status);
    }

    public function test_pre_cutoff_transactions_are_skipped_and_never_enter_review_queue(): void
    {
        $account = $this->seedConnectionWithAccount('uid-eur');
        $account->import_cutoff_date = Carbon::parse('2026-04-10');
        $account->save();

        Http::fake([
            'https://api.enablebanking.test/accounts/uid-eur/transactions*' => Http::response([
                'transactions' => [
                    $this->eurTransaction('ref-old', '3.00', 'DBIT', 'Old Coffee', bookingDate: '2026-03-01'),
                    $this->eurTransaction('ref-new', '4.00', 'DBIT', 'New Coffee', bookingDate: '2026-04-20'),
                ],
                'continuation_key' => null,
            ], 200),
        ]);

        $this->artisan('spendula:sync')->assertSuccessful();

        $this->assertSame(1, Transaction::query()->where('status', TransactionStatus::Fetched->value)->count());
        $this->assertSame(1, Transaction::query()->where('status', TransactionStatus::Skipped->value)->count());
    }

    private function seedConnectionWithAccount(string $uid): BankAccount
    {
        $connection = BankConnection::query()->create([
            'bank_slug' => 'mock',
            'enable_banking_session_id' => 'session-test',
            'status' => BankConnectionStatus::Active,
            'authorized_at' => Carbon::now(),
            'valid_until' => Carbon::now()->addDays(90),
            'raw_session_response' => [],
        ]);

        $account = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'ynab_account_type' => YnabAccountType::OnBudget,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        BankAccountSession::query()->create([
            'bank_connection_id' => $connection->id,
            'bank_account_id' => $account->id,
            'enable_banking_uid' => $uid,
        ]);

        return $account;
    }

    private function addSecondAccount(string $uid, string $currency): BankAccount
    {
        $connection = BankConnection::query()->where('status', BankConnectionStatus::Active->value)->sole();

        // The currency_mapping check requires non-base-currency accounts to be
        // tracking or unmapped. The rate-limit/multi-account scenarios that use
        // this helper want the second account to actually sync, so we mark it
        // base-currency-true regardless of the currency string.
        $account = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => $currency,
            'is_base_currency' => true,
            'active' => true,
            'ynab_account_type' => YnabAccountType::OnBudget,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        BankAccountSession::query()->create([
            'bank_connection_id' => $connection->id,
            'bank_account_id' => $account->id,
            'enable_banking_uid' => $uid,
        ]);

        return $account;
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return array<string, mixed>
     */
    private function mockTransactionsPage(array $transactions): array
    {
        return ['transactions' => $transactions, 'continuation_key' => null];
    }

    /** @return array<string, mixed> */
    private function eurTransaction(string $entryRef, string $amount = '3.45', string $cdi = 'DBIT', string $creditor = 'Coffee Shop', string $bookingDate = '2026-04-15'): array
    {
        return [
            'entry_reference' => $entryRef,
            'transaction_status' => 'BOOK',
            'booking_date' => $bookingDate,
            'value_date' => $bookingDate,
            'transaction_amount' => ['amount' => $amount, 'currency' => 'EUR'],
            'credit_debit_indicator' => $cdi,
            'creditor' => ['name' => $creditor],
            'debtor' => null,
            'remittance_information' => [],
        ];
    }
}
