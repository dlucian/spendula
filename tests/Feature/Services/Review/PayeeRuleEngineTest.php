<?php

namespace Tests\Feature\Services\Review;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Services\Review\PayeeRuleEngine;
use App\Services\Review\TransactionActions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PayeeRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_rules_with_empty_queue_returns_empty_summary(): void
    {
        $engine = new PayeeRuleEngine(new TransactionActions);

        $result = $engine->applyRules(new Collection);

        $this->assertSame([], $result['appliedIds']);
        $this->assertSame(['approved' => 0, 'skipped' => 0, 'transferred' => 0], $result['byAction']);
    }

    public function test_apply_rules_routes_approve_through_transaction_actions(): void
    {
        $tx = $this->seedTransaction(name: 'Spotify', level: 1);
        $this->seedRule('Spotify', TransactionStatus::Approved);

        $result = $this->runEngine([$tx]);

        $this->assertSame([$tx->id], $result['appliedIds']);
        $this->assertSame(['approved' => 1, 'skipped' => 0, 'transferred' => 0], $result['byAction']);
        $this->assertSame(TransactionStatus::Approved, $tx->refresh()->status);
    }

    public function test_apply_rules_skip_carries_stored_skip_reason(): void
    {
        $tx = $this->seedTransaction(name: 'SuspiciousMerchant', level: 2);
        $this->seedRule('SuspiciousMerchant', TransactionStatus::Skipped, 'flagged earlier');

        $result = $this->runEngine([$tx]);

        $fresh = $tx->refresh();
        $this->assertSame(['approved' => 0, 'skipped' => 1, 'transferred' => 0], $result['byAction']);
        $this->assertSame(TransactionStatus::Skipped, $fresh->status);
        $this->assertSame('flagged earlier', $fresh->skip_reason);
        $this->assertNotNull($fresh->skipped_at);
    }

    public function test_apply_rules_routes_transfer(): void
    {
        $tx = $this->seedTransaction(name: 'SelfTransfer', level: 0);
        $this->seedRule('SelfTransfer', TransactionStatus::Transfer);

        $result = $this->runEngine([$tx]);

        $this->assertSame(['approved' => 0, 'skipped' => 0, 'transferred' => 1], $result['byAction']);
        $this->assertSame(TransactionStatus::Transfer, $tx->refresh()->status);
    }

    public function test_apply_rules_skips_unmatched_transactions(): void
    {
        $matched = $this->seedTransaction(name: 'Spotify', level: 1);
        $unmatched = $this->seedTransaction(name: 'NewMerchant', level: 1);
        $this->seedRule('Spotify', TransactionStatus::Approved);

        $result = $this->runEngine([$matched, $unmatched]);

        $this->assertSame([$matched->id], $result['appliedIds']);
        $this->assertSame(TransactionStatus::Approved, $matched->refresh()->status);
        $this->assertSame(TransactionStatus::Fetched, $unmatched->refresh()->status);
    }

    public function test_apply_rules_match_is_case_sensitive(): void
    {
        $tx = $this->seedTransaction(name: 'spotify', level: 1);
        $this->seedRule('Spotify', TransactionStatus::Approved);

        $result = $this->runEngine([$tx]);

        $this->assertSame([], $result['appliedIds']);
        $this->assertSame(TransactionStatus::Fetched, $tx->refresh()->status);
    }

    public function test_apply_rules_isolates_by_bank_slug(): void
    {
        $this->seedBank('mock');
        $this->seedBank('other');
        $accountMock = $this->seedAccount('mock');
        $accountOther = $this->seedAccount('other');

        $txMock = $this->seedTransactionFor($accountMock, 'Spotify', level: 1);
        $txOther = $this->seedTransactionFor($accountOther, 'Spotify', level: 1);

        // Rule for `mock` only.
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        $result = $this->runEngine([$txMock, $txOther]);

        $this->assertSame([$txMock->id], $result['appliedIds']);
        $this->assertSame(TransactionStatus::Approved, $txMock->refresh()->status);
        $this->assertSame(TransactionStatus::Fetched, $txOther->refresh()->status);
    }

    /**
     * @param  list<Transaction>  $transactions
     * @return array{appliedIds: list<string>, byAction: array{approved: int, skipped: int, transferred: int}}
     */
    private function runEngine(array $transactions): array
    {
        $engine = new PayeeRuleEngine(new TransactionActions);
        $queue = new Collection;
        foreach ($transactions as $tx) {
            $queue->push($tx->load('bankAccount'));
        }

        return $engine->applyRules($queue);
    }

    private function seedRule(string $name, TransactionStatus $action, ?string $skipReason = null): PayeeRule
    {
        $this->seedBank('mock');

        return PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => $name,
            'action' => $action->value,
            'skip_reason' => $skipReason,
        ]);
    }

    private function seedBank(string $slug): void
    {
        if (Bank::query()->where('slug', $slug)->exists()) {
            return;
        }
        Bank::query()->create([
            'slug' => $slug,
            'display_name' => ucfirst($slug),
            'aspsp_name' => ucfirst($slug).' ASPSP',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);
    }

    private function seedAccount(string $bankSlug): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => $bankSlug,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    private function seedTransaction(string $name, int $level): Transaction
    {
        $this->seedBank('mock');

        return $this->seedTransactionFor($this->seedAccount('mock'), $name, $level);
    }

    private function seedTransactionFor(BankAccount $account, string $name, int $level): Transaction
    {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => "ref-{$seq}",
            'status' => TransactionStatus::Fetched,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => $name,
            'counterparty_resolution_level' => $level,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
