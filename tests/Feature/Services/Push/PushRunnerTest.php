<?php

namespace Tests\Feature\Services\Push;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PushRun;
use App\Models\PushRunError;
use App\Models\Transaction;
use App\Services\Sync\DedupHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushRunnerTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->account = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
            'ynab_account_id' => '79f0ce5c-5cff-40dd-8560-363caf59b878',
            'ynab_account_type' => YnabAccountType::OnBudget,
            'import_cutoff_date' => Carbon::parse('2026-01-01'),
        ]);
    }

    public function test_approved_transactions_become_pushed_with_ynab_ids(): void
    {
        $tx = $this->seedApproved('ref-1', -3450, 'Pingo Doce');

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([
                'data' => [
                    'transactions' => [[
                        'id' => 'ynab-tx-abc',
                        'import_id' => $this->expectedImportId($tx),
                    ]],
                    'duplicate_import_ids' => [],
                ],
            ], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Pushed, $tx->status);
        $this->assertSame('ynab-tx-abc', $tx->ynab_transaction_id);
        $this->assertNotNull($tx->pushed_at);
        $this->assertSame(1, PushRun::query()->count());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $tx = $body['transactions'][0];

            return $request->url() === 'https://api.ynab.test/v1/plans/plan-under-test/transactions'
                && $tx['account_id'] === '79f0ce5c-5cff-40dd-8560-363caf59b878'
                && $tx['amount'] === -3450
                && $tx['cleared'] === 'cleared'
                && $tx['approved'] === false
                && str_starts_with($tx['import_id'], 'SPNDL:')
                && strlen($tx['import_id']) === 36;
        });
    }

    public function test_second_run_is_a_no_op_because_ynab_transaction_id_is_set(): void
    {
        $tx = $this->seedApproved('ref-1', -3450, 'Pingo Doce');

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'ynab-abc', 'import_id' => $this->expectedImportId($tx)]], 'duplicate_import_ids' => []],
            ], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();
        Http::assertSentCount(1);

        $this->artisan('spendula:push')->assertSuccessful();
        Http::assertSentCount(1); // second run must not hit the API — nothing to push.

        $this->assertSame(1, Transaction::query()->where('status', TransactionStatus::Pushed->value)->count());
    }

    public function test_duplicate_import_ids_still_transition_to_pushed(): void
    {
        $tx = $this->seedApproved('ref-1', -3450, 'Pingo Doce');

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([
                'data' => [
                    'transactions' => [],
                    'duplicate_import_ids' => [$this->expectedImportId($tx)],
                ],
            ], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Pushed, $tx->status);
        $this->assertNotNull($tx->pushed_at);
        $this->assertNull($tx->ynab_transaction_id, 'YNAB did not return a new id for a dup.');
    }

    public function test_transfer_rows_are_pushed_with_transfer_memo_prefix(): void
    {
        $tx = $this->seedApproved('ref-1', -3450, 'Internal Move', status: TransactionStatus::Transfer);

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'ynab-xfer', 'import_id' => $this->expectedImportId($tx)]], 'duplicate_import_ids' => []],
            ], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $memo = $request->data()['transactions'][0]['memo'];

            return str_starts_with($memo, '[TRANSFER] ');
        });
    }

    public function test_retry_gating_skips_recently_attempted_rows(): void
    {
        $tx = $this->seedApproved('ref-1', -3450, 'Pingo Doce');
        $tx->last_push_attempt_at = Carbon::now()->subMinutes(5);
        $tx->push_attempt_count = 1;
        $tx->save();

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(TransactionStatus::Approved, $tx->refresh()->status);
    }

    public function test_tracking_accounts_are_skipped(): void
    {
        $this->account->ynab_account_type = YnabAccountType::Tracking;
        $this->account->save();

        $this->seedApproved('ref-1', -3450, 'Pingo Doce');

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_tracking_status_rows_are_excluded_from_push(): void
    {
        // Defensive complement to test_tracking_accounts_are_skipped: even if
        // a tracking-status row somehow lands on an on_budget account, the
        // status filter (Approved/Transfer only) keeps it out of the push.
        $this->seedApproved('ref-1', -3450, 'Pingo Doce', status: TransactionStatus::Tracking);

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([], 201),
        ]);

        $this->artisan('spendula:push')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_validation_error_leaves_transactions_approved_and_logs(): void
    {
        $this->seedApproved('ref-1', -3450, 'Pingo Doce');

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([
                'error' => ['id' => '400', 'name' => 'bad_request', 'detail' => 'account not found'],
            ], 400),
        ]);

        $exit = \Illuminate\Support\Facades\Artisan::call('spendula:push');
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Errors this run:', $out);
        $this->assertStringContainsString('HTTP 400', $out);
        $this->assertStringContainsString('account not found', $out);

        $tx = Transaction::query()->sole();
        $this->assertSame(TransactionStatus::Approved, $tx->status);
        $this->assertSame(1, $tx->push_attempt_count);
        $this->assertNotNull($tx->last_push_attempt_at);
        $this->assertNotNull($tx->last_push_error);

        $pushRun = PushRun::query()->sole();
        $this->assertSame(1, $pushRun->error_count);

        $error = PushRunError::query()->sole();
        $this->assertStringStartsWith('YNAB returned HTTP 400', (string) $error->error_detail);
        $this->assertStringContainsString("\n\nResponse: ", (string) $error->error_detail);
        $this->assertStringContainsString('"name":"bad_request"', (string) $error->error_detail);
        $this->assertStringContainsString('"detail":"account not found"', (string) $error->error_detail);
    }

    public function test_clean_push_does_not_print_error_tail(): void
    {
        $this->seedApproved('ref-clean', -1000, 'Pingo Doce');

        Http::fake([
            'https://api.ynab.test/v1/plans/plan-under-test/transactions' => Http::response([
                'data' => [
                    'transactions' => [
                        [
                            'id' => 'ynab-1',
                            'import_id' => $this->expectedImportId(Transaction::query()->sole()),
                        ],
                    ],
                    'duplicate_import_ids' => [],
                ],
            ], 201),
        ]);

        $exit = \Illuminate\Support\Facades\Artisan::call('spendula:push');
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('Errors this run:', $out);
    }

    private function seedApproved(string $entryRef, int $amountMilliunits, string $counterparty, TransactionStatus $status = TransactionStatus::Approved): Transaction
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
            'amount_milliunits' => $amountMilliunits,
            'currency' => 'EUR',
            'credit_debit_indicator' => $amountMilliunits < 0 ? CreditDebitIndicator::Debit : CreditDebitIndicator::Credit,
            'counterparty_name' => $counterparty,
            'counterparty_resolution_level' => 0,
            'occurrence' => 1,
            'push_attempt_count' => 0,
            'raw_payload' => [
                'credit_debit_indicator' => $amountMilliunits < 0 ? 'DBIT' : 'CRDT',
                'creditor' => ['name' => $counterparty],
                'debtor' => null,
            ],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
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
}
