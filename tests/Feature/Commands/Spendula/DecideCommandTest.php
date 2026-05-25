<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Services\Locks\AdvisoryLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DecideCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_no_remember(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-approve-no-remember');

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'approve'])
            ->expectsOutputToContain('rule recorded: no')
            ->assertSuccessful();

        $this->assertSame(TransactionStatus::Approved, $tx->refresh()->status);
        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_approve_with_remember(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-approve-remember');

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'approve', '--remember' => true])
            ->expectsOutputToContain('rule recorded: yes')
            ->assertSuccessful();

        $this->assertSame(TransactionStatus::Approved, $tx->refresh()->status);
        $rule = PayeeRule::query()
            ->where('bank_slug', 'mock')
            ->where('counterparty_name', 'Coffee Shop')
            ->where('action', TransactionStatus::Approved->value)
            ->first();
        $this->assertNotNull($rule);
        $this->assertSame(1, PayeeRule::query()->count());
    }

    public function test_skip_with_reason(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-skip-reason');

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'skip', '--reason' => 'ATM withdrawal'])
            ->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Skipped, $tx->status);
        $this->assertSame('ATM withdrawal', $tx->skip_reason);
        $this->assertNotNull($tx->skipped_at);
    }

    public function test_skip_with_remember_and_reason(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-skip-remember-reason');

        $this->artisan('spendula:decide', [
            'txn_id' => $tx->id,
            'action' => 'skip',
            '--reason' => 'ATM withdrawal',
            '--remember' => true,
        ])
            ->expectsOutputToContain('rule recorded: yes')
            ->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Skipped, $tx->status);
        $rule = PayeeRule::query()
            ->where('bank_slug', 'mock')
            ->where('counterparty_name', 'Coffee Shop')
            ->where('action', TransactionStatus::Skipped->value)
            ->first();
        $this->assertNotNull($rule);
        $this->assertSame('ATM withdrawal', $rule->skip_reason);
    }

    public function test_transfer_no_remember(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-transfer-no-remember');

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'transfer'])
            ->expectsOutputToContain('rule recorded: no')
            ->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Transfer, $tx->status);
        $this->assertNull($tx->skip_reason);
        $this->assertNull($tx->skipped_at);
    }

    public function test_transfer_with_remember(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-transfer-remember');

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'transfer', '--remember' => true])
            ->expectsOutputToContain('rule recorded: yes')
            ->assertSuccessful();

        $tx->refresh();
        $this->assertSame(TransactionStatus::Transfer, $tx->status);
        $rule = PayeeRule::query()
            ->where('bank_slug', 'mock')
            ->where('counterparty_name', 'Coffee Shop')
            ->where('action', TransactionStatus::Transfer->value)
            ->first();
        $this->assertNotNull($rule);
    }

    public function test_reason_on_non_skip_action_is_rejected(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-reason-non-skip');
        $originalStatus = $tx->status;

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'approve', '--reason' => 'foo'])
            ->assertFailed();

        $this->assertSame($originalStatus, $tx->refresh()->status);
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-unknown-action');

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'burn'])
            ->assertFailed();

        $this->assertSame(TransactionStatus::Fetched, $tx->refresh()->status);
    }

    public function test_already_decided_row_is_refused(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-already-decided');
        $tx->status = TransactionStatus::Approved;
        $tx->save();

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'skip'])
            ->assertFailed();

        $this->assertSame(TransactionStatus::Approved, $tx->refresh()->status);
        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_missing_transaction_is_refused(): void
    {
        $missingId = '00000000-0000-0000-0000-000000000000';

        $this->artisan('spendula:decide', ['txn_id' => $missingId, 'action' => 'approve'])
            ->assertFailed();
    }

    public function test_lock_busy_exits_non_zero_without_mutation(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-lock-busy');

        // Acquire the REVIEW lock from a separate Postgres session so the
        // artisan command's tryAcquire fails (advisory locks are re-entrant
        // within the same session, so we must use a second PDO connection).
        $secondPdo = $this->openSecondConnectionAndLock(AdvisoryLock::REVIEW);

        try {
            $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'approve'])
                ->assertFailed();
        } finally {
            $secondPdo->exec('SELECT pg_advisory_unlock('.AdvisoryLock::REVIEW.')');
            $secondPdo = null;
        }

        $this->assertSame(TransactionStatus::Fetched, $tx->refresh()->status);
    }

    public function test_remember_on_guarded_row_writes_no_rule_but_applies_decision(): void
    {
        // 'ATM' is on the bank_internal_payees denylist in config/spendula.php.
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-guarded-atm', counterpartyName: 'ATM');

        $this->artisan('spendula:decide', ['txn_id' => $tx->id, 'action' => 'approve', '--remember' => true])
            ->expectsOutputToContain('rule recorded: no')
            ->assertSuccessful();

        $this->assertSame(TransactionStatus::Approved, $tx->refresh()->status);
        $this->assertSame(0, PayeeRule::query()->count());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function seedTransaction(
        BankAccount $account,
        int $level,
        string $entryRef,
        string $counterpartyName = 'Coffee Shop',
    ): Transaction {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'd'),
            'entry_reference' => $entryRef,
            'status' => TransactionStatus::Fetched,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => $account->currency,
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => $counterpartyName,
            'counterparty_resolution_level' => $level,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }

    private function openSecondConnectionAndLock(int $lockKey): \PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database'],
        );
        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query("SELECT pg_try_advisory_lock({$lockKey}) AS acquired");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (! $row || ! $row['acquired']) {
            throw new \RuntimeException('Could not acquire lock from separate connection.');
        }

        return $pdo;
    }
}
