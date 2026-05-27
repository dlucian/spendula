<?php

namespace Tests\Feature\Status;

use App\Enums\BankConnectionStatus;
use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Transaction;
use App\Services\Status\StatusSnapshotBuilder;
use App\Services\Status\Thresholds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatusSnapshotBuilderInactiveAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-19 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_queued_counts_exclude_inactive_accounts(): void
    {
        $this->seedBank('mock', 'Mock Bank');
        $this->seedConnection('mock', BankConnectionStatus::Active, Carbon::now()->addDays(60));

        $active = $this->seedAccount('mock', active: true);
        $inactive = $this->seedAccount('mock', active: false);

        // Inactive account has 5 approved rows — must not count.
        for ($i = 0; $i < 5; $i++) {
            $this->seedTransaction($inactive, TransactionStatus::Approved);
        }

        // Active account has 0 queued rows.
        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: true);

        $this->assertCount(1, $snapshot->banks);
        $this->assertSame(0, $snapshot->banks[0]->queuedCounts['approved']);
    }

    public function test_stuck_transactions_exclude_inactive_accounts(): void
    {
        $this->seedBank('mock', 'Mock Bank');
        $this->seedConnection('mock', BankConnectionStatus::Active, Carbon::now()->addDays(60));

        $inactive = $this->seedAccount('mock', active: false);
        $tx = $this->seedTransaction($inactive, TransactionStatus::Approved);
        $tx->push_attempt_count = Thresholds::PUSH_STUCK_ATTEMPTS;
        $tx->save();

        $snapshot = (new StatusSnapshotBuilder)->build(includeMock: true);

        $this->assertCount(0, $snapshot->stuckTransactions);
    }

    private function seedBank(string $slug, string $displayName, bool $active = true): Bank
    {
        return Bank::query()->create([
            'slug' => $slug,
            'display_name' => $displayName,
            'aspsp_name' => $displayName,
            'aspsp_country' => 'FI',
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
            'authorized_at' => Carbon::now()->subHour(),
            'valid_until' => $validUntil ?? Carbon::now()->addDays(60),
            'raw_session_response' => ['mocked' => true],
            'last_synced_at' => $lastSyncedAt,
        ]);
    }

    private function seedAccount(string $bankSlug, bool $active = true): BankAccount
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
            'dedup_hash' => substr(md5((string) $seq.Str::random(8)), 0, 32),
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
