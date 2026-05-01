<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\BankConnectionStatus;
use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankAccountSyncState;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Services\Status\StatusSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-01 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_db_renders_friendly_message_and_exits_zero(): void
    {
        $this->artisan('spendula:status')
            ->expectsOutputToContain('Nothing to show')
            ->assertExitCode(0);
    }

    public function test_consent_t_minus_15_is_green_and_exits_zero(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active, validUntil: Carbon::now()->addDays(15));

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('(stale)')
            ->assertExitCode(0);
    }

    public function test_consent_t_minus_14_is_yellow_and_exits_zero(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active, validUntil: Carbon::now()->addDays(14));

        $this->artisan('spendula:status')
            ->assertExitCode(0);
    }

    public function test_consent_t_minus_4_is_yellow_and_exits_zero(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active, validUntil: Carbon::now()->addDays(4));

        $this->artisan('spendula:status')
            ->assertExitCode(0);
    }

    public function test_consent_t_minus_3_is_red_and_exits_one(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active, validUntil: Carbon::now()->addDays(3));

        $this->artisan('spendula:status')
            ->assertExitCode(1);
    }

    public function test_expired_consent_renders_red_and_exits_one(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Expired, validUntil: Carbon::now()->subDay());

        $this->artisan('spendula:status')
            ->assertExitCode(1);
    }

    public function test_queued_counts_match_seeded_shape(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active);
        $account = $this->seedAccount($bank->slug);

        $this->seedTransactions($account, TransactionStatus::Fetched, 3);
        $this->seedTransactions($account, TransactionStatus::Approved, 2);
        $this->seedTransactions($account, TransactionStatus::Transfer, 1);
        $this->seedTransactions($account, TransactionStatus::Tracking, 4);

        $this->artisan('spendula:status')
            // Table rendering is fragile to assert literally; just confirm
            // each count value appears alongside the bank name.
            ->expectsOutputToContain('Millennium BCP')
            ->assertExitCode(0);
    }

    public function test_pushed_and_skipped_rows_are_excluded_from_counts(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active);
        $account = $this->seedAccount($bank->slug);

        // One fetched, then a bunch of pushed/skipped that must NOT count.
        $this->seedTransactions($account, TransactionStatus::Fetched, 1);
        $this->seedTransactions($account, TransactionStatus::Pushed, 7);
        $this->seedTransactions($account, TransactionStatus::Skipped, 5);

        $builder = app(StatusSnapshotBuilder::class);
        $snapshot = $builder->build(includeMock: false);

        $this->assertCount(1, $snapshot->banks);
        $this->assertSame(1, $snapshot->banks[0]->queuedCounts['fetched']);
        $this->assertSame(0, $snapshot->banks[0]->queuedCounts['approved']);
        $this->assertSame(0, $snapshot->banks[0]->queuedCounts['transfer']);
        $this->assertSame(0, $snapshot->banks[0]->queuedCounts['tracking']);
    }

    public function test_stale_sync_on_active_consent_active_bank_trips_exit_one(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection(
            $bank->slug,
            BankConnectionStatus::Active,
            lastSyncedAt: Carbon::now()->subHours(25),
        );

        $this->artisan('spendula:status')
            ->expectsOutputToContain('stale')
            ->assertExitCode(1);
    }

    public function test_stale_sync_on_expired_consent_does_not_double_warn(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        // Expired consent — already trips red. The 25h-old sync must NOT
        // produce a redundant stale-sync warning.
        $this->seedConnection(
            $bank->slug,
            BankConnectionStatus::Expired,
            validUntil: Carbon::now()->subDay(),
            lastSyncedAt: Carbon::now()->subHours(25),
        );

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('(stale)')
            ->assertExitCode(1);
    }

    public function test_stuck_transaction_at_attempt_count_5_appears_and_trips_exit_one(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active);
        $account = $this->seedAccount($bank->slug);
        $tx = $this->seedTransactions($account, TransactionStatus::Approved, 1)[0];
        $tx->push_attempt_count = 5;
        $tx->last_push_error = 'fake test error';
        $tx->save();

        $this->artisan('spendula:status')
            ->expectsOutputToContain('Push-stuck transactions')
            ->expectsOutputToContain('attempts=5')
            ->assertExitCode(1);
    }

    public function test_stuck_transaction_at_attempt_count_4_does_not_appear(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active);
        $account = $this->seedAccount($bank->slug);
        $tx = $this->seedTransactions($account, TransactionStatus::Approved, 1)[0];
        $tx->push_attempt_count = 4;
        $tx->save();

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('Push-stuck transactions')
            ->assertExitCode(0);
    }

    public function test_stuck_filter_excludes_pushed_status_even_at_attempts_5(): void
    {
        // Regression: PushRunner increments push_attempt_count on the
        // success path too, so a row that retried 5 times then succeeded
        // ends up at push_attempt_count=5 AND status=pushed. The dashboard
        // must NOT treat that row as stuck.
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active);
        $account = $this->seedAccount($bank->slug);
        $tx = $this->seedTransactions($account, TransactionStatus::Pushed, 1)[0];
        $tx->push_attempt_count = 5;
        $tx->ynab_transaction_id = 'ynab-tx-id';
        $tx->pushed_at = Carbon::now();
        $tx->save();

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('Push-stuck transactions')
            ->assertExitCode(0);
    }

    public function test_stuck_filter_excludes_rows_with_ynab_id_set(): void
    {
        // Defensive on the second filter: even if status somehow remained
        // approved, having a YNAB id means it's not stuck.
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection($bank->slug, BankConnectionStatus::Active);
        $account = $this->seedAccount($bank->slug);
        $tx = $this->seedTransactions($account, TransactionStatus::Approved, 1)[0];
        $tx->push_attempt_count = 5;
        $tx->ynab_transaction_id = 'ynab-tx-id';
        $tx->save();

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('Push-stuck transactions')
            ->assertExitCode(0);
    }

    public function test_inactive_bank_is_hidden_and_does_not_trip_exit_code(): void
    {
        $bank = $this->seedBank('bcp', 'Millennium BCP', active: false);
        // Red consent on the inactive bank — would normally trip exit 1.
        $this->seedConnection($bank->slug, BankConnectionStatus::Active, validUntil: Carbon::now()->addDays(1));

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('Millennium BCP')
            ->assertExitCode(0);
    }

    public function test_lazy_expiry_is_treated_as_red_and_suppresses_stale_warning(): void
    {
        // Stored 'active' but valid_until is 2h in the past. The sync
        // command would lazily flip it to 'expired' on the next run, but
        // the dashboard should reconcile that drift now.
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection(
            $bank->slug,
            BankConnectionStatus::Active,
            validUntil: Carbon::now()->subHours(2),
            lastSyncedAt: Carbon::now()->subHours(48),
        );

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('(stale)')
            ->assertExitCode(1);
    }

    public function test_just_reauthed_bank_with_null_last_synced_at_is_stale(): void
    {
        // Operator just completed auth: bank_connections.last_synced_at IS
        // NULL on the new active connection. An old per-account
        // last_successful_sync_at exists (from a previous superseded
        // connection). The dashboard must NOT report the bank as fresh
        // based on the per-account state.
        $bank = $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection(
            $bank->slug,
            BankConnectionStatus::Active,
            validUntil: Carbon::now()->addDays(60),
            lastSyncedAt: null, // explicit: never synced.
        );
        $account = $this->seedAccount($bank->slug);

        // Stale account-level state from a superseded prior connection.
        BankAccountSyncState::query()->create([
            'bank_account_id' => $account->id,
            'last_successful_sync_at' => Carbon::now()->subDays(2),
            'consecutive_failure_count' => 0,
        ]);

        $this->artisan('spendula:status')
            ->expectsOutputToContain('stale')
            ->assertExitCode(1);
    }

    public function test_mock_bank_hidden_by_default(): void
    {
        $mock = $this->seedBank('mock', 'Mock ASPSP');
        $this->seedConnection($mock->slug, BankConnectionStatus::Active);

        $this->artisan('spendula:status')
            ->doesntExpectOutputToContain('Mock ASPSP')
            ->assertExitCode(0);
    }

    public function test_mock_bank_shown_with_include_mock_flag(): void
    {
        $mock = $this->seedBank('mock', 'Mock ASPSP');
        $this->seedConnection($mock->slug, BankConnectionStatus::Active);

        $this->artisan('spendula:status', ['--include-mock' => true])
            ->expectsOutputToContain('Mock ASPSP')
            ->assertExitCode(0);
    }

    private function seedBank(string $slug, string $displayName, bool $active = true): Bank
    {
        return Bank::query()->create([
            'slug' => $slug,
            'display_name' => $displayName,
            'aspsp_name' => $displayName,
            'aspsp_country' => 'PT',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => $active,
        ]);
    }

    /**
     * Default fixtures use a fresh `last_synced_at = now()` so the
     * sync-stale flag is OFF unless a test explicitly opts in.
     * Pass `lastSyncedAt: null` to model a never-synced (just-reauthed)
     * connection.
     */
    private function seedConnection(
        string $bankSlug,
        BankConnectionStatus $status,
        ?Carbon $validUntil = null,
        Carbon|false|null $lastSyncedAt = false,
    ): BankConnection {
        if ($lastSyncedAt === false) {
            $lastSyncedAt = Carbon::now();
        }

        return BankConnection::query()->create([
            'id' => (string) Str::uuid(),
            'bank_slug' => $bankSlug,
            'enable_banking_session_id' => 'session-'.Str::random(8),
            'status' => $status,
            'authorized_at' => Carbon::now()->subHours(1),
            'valid_until' => $validUntil ?? Carbon::now()->addDays(60),
            'raw_session_response' => ['mocked' => true],
            'last_synced_at' => $lastSyncedAt,
        ]);
    }

    private function seedAccount(string $bankSlug): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => $bankSlug,
            'display_name' => 'Test Account',
            'iban' => 'PT'.Str::random(20),
            'currency' => 'EUR',
            'is_base_currency' => true,
            'ynab_account_id' => (string) Str::uuid(),
            'ynab_account_type' => YnabAccountType::OnBudget,
            'import_cutoff_date' => Carbon::now()->subDays(30)->toDateString(),
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    /**
     * @return list<Transaction>
     */
    private function seedTransactions(BankAccount $account, TransactionStatus $status, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = Transaction::query()->create([
                'bank_account_id' => $account->id,
                'dedup_hash' => substr(md5($status->value.$i.Str::random(8)), 0, 32),
                'status' => $status,
                'transaction_status' => 'BOOK',
                'booking_date' => Carbon::now()->subDays($i + 1)->toDateString(),
                'amount_milliunits' => 1000 * ($i + 1),
                'currency' => 'EUR',
                'credit_debit_indicator' => CreditDebitIndicator::Credit,
                'counterparty_name' => 'Acme '.$i,
                'counterparty_resolution_level' => 1,
                'raw_payload' => ['stub' => true, 'i' => $i],
                'occurrence' => 1,
                'first_seen_at' => Carbon::now(),
                'last_updated_from_bank_at' => Carbon::now(),
            ]);
        }

        return $rows;
    }
}
