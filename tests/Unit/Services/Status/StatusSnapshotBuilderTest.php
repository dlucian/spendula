<?php

namespace Tests\Unit\Services\Status;

use App\Enums\BankConnectionStatus;
use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\PushRun;
use App\Models\PushRunError;
use App\Models\SyncRun;
use App\Models\SyncRunError;
use App\Models\Transaction;
use App\Services\Status\StatusSnapshot;
use App\Services\Status\StatusSnapshotBuilder;
use App\Services\Status\Thresholds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusSnapshotBuilderTest extends TestCase
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

    public function test_consent_threshold_transitions(): void
    {
        $cases = [
            ['days' => 15, 'expected' => 'green'],
            ['days' => 14, 'expected' => 'yellow'],
            ['days' => 4, 'expected' => 'yellow'],
            ['days' => 3, 'expected' => 'red'],
        ];

        foreach ($cases as $i => $c) {
            $slug = 'bank'.$i;
            $this->seedBank($slug, 'Bank '.$i);
            $this->seedConnection(
                $slug,
                BankConnectionStatus::Active,
                Carbon::now()->addDays($c['days']),
            );
        }

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        // Index banks by slug for easy lookup.
        $bySlug = [];
        foreach ($snapshot->banks as $b) {
            $bySlug[$b->slug] = $b;
        }

        foreach ($cases as $i => $c) {
            $this->assertSame(
                $c['expected'],
                $bySlug['bank'.$i]->consentWarningLevel,
                "T-{$c['days']} should be {$c['expected']}",
            );
        }
    }

    public function test_expired_connection_renders_red_with_blank_days_remaining(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Expired, Carbon::now()->subDay());

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertSame('red', $snapshot->banks[0]->consentWarningLevel);
        $this->assertNull($snapshot->banks[0]->consentDaysRemaining);
        $this->assertSame('expired', $snapshot->banks[0]->effectiveConsentStatus);
        $this->assertTrue($snapshot->hasRedOrStuckRows());
    }

    public function test_no_connection_renders_na_warning_level(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertSame('na', $snapshot->banks[0]->consentWarningLevel);
        $this->assertSame('none', $snapshot->banks[0]->consentStatus);
    }

    public function test_zero_fill_of_queued_counts_for_banks_with_no_transactions(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active);

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertSame(
            ['fetched' => 0, 'approved' => 0, 'transfer' => 0, 'tracking' => 0],
            $snapshot->banks[0]->queuedCounts,
        );
    }

    public function test_lazy_expiry_reconciliation_to_effective_status(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active, Carbon::now()->subHours(2));

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertSame('active', $snapshot->banks[0]->consentStatus);
        $this->assertSame('expired', $snapshot->banks[0]->effectiveConsentStatus);
        $this->assertSame('red', $snapshot->banks[0]->consentWarningLevel);
    }

    public function test_has_red_or_stuck_rows_false_when_all_green_and_no_stuck(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active, Carbon::now()->addDays(60), Carbon::now());

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertFalse($snapshot->hasRedOrStuckRows());
    }

    public function test_has_red_or_stuck_rows_true_when_consent_red(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active, Carbon::now()->addDays(2), Carbon::now());

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertTrue($snapshot->hasRedOrStuckRows());
    }

    public function test_has_red_or_stuck_rows_true_when_sync_stale(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active, Carbon::now()->addDays(60), Carbon::now()->subHours(25));

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertTrue($snapshot->banks[0]->syncStale);
        $this->assertTrue($snapshot->hasRedOrStuckRows());
    }

    public function test_has_red_or_stuck_rows_true_when_stuck_transaction_present(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active, Carbon::now()->addDays(60), Carbon::now());
        $account = $this->seedAccount('bcp');
        $tx = $this->seedTransaction($account, TransactionStatus::Approved);
        $tx->push_attempt_count = 5;
        $tx->save();

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertCount(1, $snapshot->stuckTransactions);
        $this->assertTrue($snapshot->hasRedOrStuckRows());
    }

    public function test_inactive_bank_is_excluded_from_snapshot(): void
    {
        $this->seedBank('bcp', 'Millennium BCP', active: false);
        $this->seedConnection('bcp', BankConnectionStatus::Active, Carbon::now()->addDays(2));

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertCount(0, $snapshot->banks);
        $this->assertFalse($snapshot->hasRedOrStuckRows());
    }

    public function test_injected_clock_is_used_consistently(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active, Carbon::parse('2026-06-01 00:00:00'));

        $fakeNow = Carbon::parse('2026-05-15 00:00:00');

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false, now: $fakeNow);

        $this->assertSame($fakeNow->toIso8601String(), $snapshot->generatedAt->toIso8601String());
        // 17 days between fakeNow and validUntil — should be green.
        $this->assertSame('green', $snapshot->banks[0]->consentWarningLevel);
    }

    public function test_just_reauthed_bank_with_null_last_synced_at_is_stale(): void
    {
        $this->seedBank('bcp', 'Millennium BCP');
        $this->seedConnection(
            'bcp',
            BankConnectionStatus::Active,
            Carbon::now()->addDays(60),
            null,
        );

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertTrue($snapshot->banks[0]->syncStale);
        $this->assertNull($snapshot->banks[0]->lastSyncedAt);
    }

    public function test_snapshot_is_empty_with_no_banks_and_no_stuck(): void
    {
        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertTrue($snapshot->isEmpty);
        $this->assertSame([], $snapshot->banks);
        $this->assertSame([], $snapshot->stuckTransactions);
    }

    public function test_returns_status_snapshot_instance(): void
    {
        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertInstanceOf(StatusSnapshot::class, $snapshot);
    }

    public function test_recent_errors_panel_loads_sync_and_push_errors_within_window(): void
    {
        $this->seedBank('bcp', 'BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active);
        $account = $this->seedAccount('bcp');

        $syncRun = $this->seedSyncRun('bcp');
        $this->seedSyncRunError($syncRun, $account, 'http_error', 'EB body 422', Carbon::now()->subMinutes(30), 422);

        $pushRun = $this->seedPushRun();
        $tx = $this->seedTransaction($account, TransactionStatus::Approved);
        $this->seedPushRunError($pushRun, $tx, 'validation', 'YNAB body 400', Carbon::now()->subMinutes(10), 400);

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertCount(2, $snapshot->recentErrors);
        // Newest first.
        $this->assertSame('push', $snapshot->recentErrors[0]->runKind);
        $this->assertSame(400, $snapshot->recentErrors[0]->httpStatus);
        $this->assertSame('BCP', $snapshot->recentErrors[0]->bankDisplayName);
        $this->assertSame('Test Account', $snapshot->recentErrors[0]->bankAccountDisplayName);
        $this->assertSame('sync', $snapshot->recentErrors[1]->runKind);
        $this->assertSame(422, $snapshot->recentErrors[1]->httpStatus);
    }

    public function test_recent_errors_panel_excludes_rows_at_or_past_24h_cutoff(): void
    {
        $this->seedBank('bcp', 'BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active);
        $account = $this->seedAccount('bcp');
        $syncRun = $this->seedSyncRun('bcp');

        $window = Thresholds::RECENT_ERRORS_WINDOW_HOURS;

        // 1 second inside the window — included.
        $this->seedSyncRunError($syncRun, $account, 'http_error', 'inside', Carbon::now()->subHours($window)->addSecond(), 422);
        // Exactly at the cutoff — included (created_at >= cutoff).
        $this->seedSyncRunError($syncRun, $account, 'http_error', 'at-cutoff', Carbon::now()->subHours($window), 422);
        // 1 second past — excluded.
        $this->seedSyncRunError($syncRun, $account, 'http_error', 'past', Carbon::now()->subHours($window)->subSecond(), 422);

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $details = array_map(fn ($e) => $e->detail, $snapshot->recentErrors);
        $this->assertContains('inside', $details);
        $this->assertContains('at-cutoff', $details);
        $this->assertNotContains('past', $details);
    }

    public function test_recent_errors_panel_caps_at_threshold_limit(): void
    {
        $this->seedBank('bcp', 'BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active);
        $account = $this->seedAccount('bcp');
        $syncRun = $this->seedSyncRun('bcp');

        // Seed double the cap so order + slice are exercised.
        for ($i = 0; $i < Thresholds::RECENT_ERRORS_LIMIT * 2; $i++) {
            $this->seedSyncRunError(
                $syncRun,
                $account,
                'http_error',
                'err-'.$i,
                Carbon::now()->subMinutes($i + 1),
                422,
            );
        }

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertCount(Thresholds::RECENT_ERRORS_LIMIT, $snapshot->recentErrors);
        // Newest (err-0) first; oldest within the cap is err-9 when LIMIT=10.
        $this->assertSame('err-0', $snapshot->recentErrors[0]->detail);
        $this->assertSame('err-'.(Thresholds::RECENT_ERRORS_LIMIT - 1), $snapshot->recentErrors[Thresholds::RECENT_ERRORS_LIMIT - 1]->detail);
    }

    public function test_recent_errors_panel_excludes_mock_when_include_mock_false(): void
    {
        $this->seedBank('mock', 'Mock ASPSP');
        $this->seedBank('bcp', 'BCP');
        $this->seedConnection('mock', BankConnectionStatus::Active);
        $this->seedConnection('bcp', BankConnectionStatus::Active);
        $mockAccount = $this->seedAccount('mock');
        $realAccount = $this->seedAccount('bcp');
        $syncRun = $this->seedSyncRun('bcp');

        $this->seedSyncRunError($syncRun, $mockAccount, 'http_error', 'mock-err', Carbon::now()->subMinutes(5), 422);
        $this->seedSyncRunError($syncRun, $realAccount, 'http_error', 'real-err', Carbon::now()->subMinutes(10), 422);

        $excluded = (new StatusSnapshotBuilder)->build(includeMock: false);
        $details = array_map(fn ($e) => $e->detail, $excluded->recentErrors);
        $this->assertContains('real-err', $details);
        $this->assertNotContains('mock-err', $details);

        $included = (new StatusSnapshotBuilder)->build(includeMock: true);
        $detailsIncluded = array_map(fn ($e) => $e->detail, $included->recentErrors);
        $this->assertContains('real-err', $detailsIncluded);
        $this->assertContains('mock-err', $detailsIncluded);
    }

    public function test_recent_errors_panel_includes_connection_level_sync_errors_without_account(): void
    {
        $this->seedBank('bcp', 'BCP');
        $this->seedConnection('bcp', BankConnectionStatus::Active);
        $syncRun = $this->seedSyncRun('bcp');
        $this->seedSyncRunError($syncRun, null, 'consent_expired', 'connection-level', Carbon::now()->subMinutes(5), 401);

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertCount(1, $snapshot->recentErrors);
        $this->assertSame('connection-level', $snapshot->recentErrors[0]->detail);
        $this->assertNull($snapshot->recentErrors[0]->bankDisplayName);
        $this->assertNull($snapshot->recentErrors[0]->bankAccountDisplayName);
    }

    public function test_recent_errors_excludes_mock_connection_level_errors_via_sync_run_bank_slug(): void
    {
        // A sync error tied to a mock-bank run but with bank_account_id = NULL
        // (consent-level failure) joins through bank_accounts → banks as
        // (null, null). Without the sync_runs.bank_slug fallback the mock
        // filter would leak this row through when includeMock=false.
        $this->seedBank('mock', 'Mock ASPSP');
        $this->seedBank('bcp', 'BCP');
        $this->seedConnection('mock', BankConnectionStatus::Active);
        $this->seedConnection('bcp', BankConnectionStatus::Active);
        $realAccount = $this->seedAccount('bcp');

        $mockSyncRun = $this->seedSyncRun('mock');
        $this->seedSyncRunError($mockSyncRun, null, 'consent_expired', 'mock-connection-err', Carbon::now()->subMinutes(5), 401);

        $bcpSyncRun = $this->seedSyncRun('bcp');
        $this->seedSyncRunError($bcpSyncRun, $realAccount, 'http_error', 'real-err', Carbon::now()->subMinutes(10), 422);

        $excluded = (new StatusSnapshotBuilder)->build(includeMock: false);
        $details = array_map(fn ($e) => $e->detail, $excluded->recentErrors);
        $this->assertContains('real-err', $details);
        $this->assertNotContains('mock-connection-err', $details, 'mock connection-level error must be filtered by sync_runs.bank_slug');

        $included = (new StatusSnapshotBuilder)->build(includeMock: true);
        $detailsIncluded = array_map(fn ($e) => $e->detail, $included->recentErrors);
        $this->assertContains('mock-connection-err', $detailsIncluded);
    }

    public function test_snapshot_with_only_recent_errors_is_not_empty(): void
    {
        // No active banks, no stuck transactions, but a recent error exists.
        // The "Nothing to show" early-return in StatusRenderer keys off
        // isEmpty, so recent errors must keep isEmpty=false or the panel
        // gets suppressed exactly when it matters most.
        $this->seedBank('bcp', 'BCP', active: false);
        $this->seedConnection('bcp', BankConnectionStatus::Active);
        $account = $this->seedAccount('bcp');
        $syncRun = $this->seedSyncRun('bcp');
        $this->seedSyncRunError($syncRun, $account, 'http_error', 'lonely-err', Carbon::now()->subMinutes(5), 422);

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: false);

        $this->assertFalse($snapshot->isEmpty);
        $this->assertCount(1, $snapshot->recentErrors);
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

    private function seedConnection(
        string $bankSlug,
        BankConnectionStatus $status,
        ?Carbon $validUntil = null,
        ?Carbon $lastSyncedAt = null,
    ): BankConnection {
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

    private function seedSyncRun(string $bankSlug): SyncRun
    {
        return SyncRun::query()->create([
            'bank_slug' => $bankSlug,
            'started_at' => Carbon::now()->subHour(),
            'finished_at' => Carbon::now()->subMinutes(50),
            'transactions_inserted' => 0,
            'transactions_updated' => 0,
            'transactions_deduped' => 0,
            'error_count' => 1,
        ]);
    }

    private function seedSyncRunError(
        SyncRun $run,
        ?BankAccount $account,
        string $errorType,
        string $detail,
        Carbon $createdAt,
        ?int $httpStatus = null,
    ): SyncRunError {
        return SyncRunError::query()->create([
            'sync_run_id' => $run->id,
            'bank_account_id' => $account?->id,
            'error_type' => $errorType,
            'error_detail' => $detail,
            'http_status' => $httpStatus,
            'created_at' => $createdAt,
        ]);
    }

    private function seedPushRun(): PushRun
    {
        return PushRun::query()->create([
            'started_at' => Carbon::now()->subHour(),
            'finished_at' => Carbon::now()->subMinutes(50),
            'transactions_pushed' => 0,
            'transactions_duplicate' => 0,
            'error_count' => 1,
        ]);
    }

    private function seedPushRunError(
        PushRun $run,
        Transaction $transaction,
        string $errorType,
        string $detail,
        Carbon $createdAt,
        ?int $httpStatus = null,
    ): PushRunError {
        return PushRunError::query()->create([
            'push_run_id' => $run->id,
            'transaction_id' => $transaction->id,
            'error_type' => $errorType,
            'error_detail' => $detail,
            'http_status' => $httpStatus,
            'created_at' => $createdAt,
        ]);
    }

    private function seedTransaction(BankAccount $account, TransactionStatus $status): Transaction
    {
        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => substr(md5(Str::random(16)), 0, 32),
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => Carbon::now()->subDay()->toDateString(),
            'amount_milliunits' => 12345,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Credit,
            'counterparty_name' => 'Acme Inc',
            'counterparty_resolution_level' => 1,
            'raw_payload' => ['stub' => true],
            'occurrence' => 1,
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
