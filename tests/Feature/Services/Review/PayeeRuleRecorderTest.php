<?php

namespace Tests\Feature\Services\Review;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Services\Review\PayeeRuleRecorder;
use App\Services\Review\RecordResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PayeeRuleRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_creates_rule_for_approved_transaction(): void
    {
        $tx = $this->seedTransaction(name: 'Spotify', level: 1);

        $result = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Approved);

        $this->assertSame(RecordResult::Created, $result);
        $rule = PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail();
        $this->assertSame('mock', $rule->bank_slug);
        $this->assertSame(TransactionStatus::Approved, $rule->action);
        $this->assertNull($rule->skip_reason);
    }

    public function test_record_persists_skip_reason_on_skipped_action(): void
    {
        $tx = $this->seedTransaction(name: 'SuspiciousMerchant', level: 2);

        $result = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Skipped, '  not mine  ');

        $this->assertSame(RecordResult::Created, $result);
        $rule = PayeeRule::query()->where('counterparty_name', 'SuspiciousMerchant')->firstOrFail();
        $this->assertSame(TransactionStatus::Skipped, $rule->action);
        $this->assertSame('not mine', $rule->skip_reason);
    }

    public function test_record_blank_skip_reason_stored_as_null(): void
    {
        $tx = $this->seedTransaction(name: 'NoReasonMerchant', level: 1);

        (new PayeeRuleRecorder)->record($tx, TransactionStatus::Skipped, '   ');

        $rule = PayeeRule::query()->where('counterparty_name', 'NoReasonMerchant')->firstOrFail();
        $this->assertNull($rule->skip_reason);
    }

    public function test_record_returns_already_exists_for_repeat_pair(): void
    {
        $tx = $this->seedTransaction(name: 'Spotify', level: 1);

        $first = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Approved);
        $second = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Skipped, 'should not overwrite');

        $this->assertSame(RecordResult::Created, $first);
        $this->assertSame(RecordResult::AlreadyExists, $second);
        $rule = PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail();
        $this->assertSame(TransactionStatus::Approved, $rule->action, 'Existing rule must not be overwritten by repeat record() call.');
    }

    public function test_record_skips_when_resolution_level_is_unknown(): void
    {
        $tx = $this->seedTransaction(name: '(Unknown)', level: 4);

        $result = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Approved);

        $this->assertSame(RecordResult::SkippedByGuard, $result);
        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_record_skips_bank_internal_denylist_case_insensitively(): void
    {
        config()->set('spendula.payee_rule_guards.bank_internal_payees', ['REVOLUT']);
        $tx = $this->seedTransaction(name: 'revolut', level: 1);

        $result = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Approved);

        $this->assertSame(RecordResult::SkippedByGuard, $result);
        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_record_skips_operator_name_denylist(): void
    {
        config()->set('spendula.payee_rule_guards.bank_internal_payees', []);
        config()->set('spendula.payee_rule_guards.operator_names', ['JANE DOE']);
        $tx = $this->seedTransaction(name: 'Jane Doe', level: 0);

        $result = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Transfer);

        $this->assertSame(RecordResult::SkippedByGuard, $result);
        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_record_skips_when_counterparty_name_is_null(): void
    {
        $tx = $this->seedTransaction(name: null, level: 4);

        $result = (new PayeeRuleRecorder)->record($tx, TransactionStatus::Approved);

        $this->assertSame(RecordResult::SkippedByGuard, $result);
        $this->assertSame(0, PayeeRule::query()->count());
    }

    public function test_find_for_returns_matching_rule(): void
    {
        $tx = $this->seedTransaction(name: 'Spotify', level: 1);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        $found = (new PayeeRuleRecorder)->findFor($tx);

        $this->assertNotNull($found);
        $this->assertSame('Spotify', $found->counterparty_name);
    }

    public function test_find_for_returns_null_when_no_rule(): void
    {
        $tx = $this->seedTransaction(name: 'NewMerchant', level: 1);

        $this->assertNull((new PayeeRuleRecorder)->findFor($tx));
    }

    public function test_update_changes_action_and_clears_skip_reason_on_non_skip_transition(): void
    {
        $rule = PayeeRule::query()->create([
            'bank_slug' => $this->seedMockBank(),
            'counterparty_name' => 'X',
            'action' => TransactionStatus::Skipped->value,
            'skip_reason' => 'old reason',
        ]);

        (new PayeeRuleRecorder)->update($rule, TransactionStatus::Approved);

        $rule->refresh();
        $this->assertSame(TransactionStatus::Approved, $rule->action);
        $this->assertNull($rule->skip_reason);
    }

    public function test_delete_removes_rule(): void
    {
        $rule = PayeeRule::query()->create([
            'bank_slug' => $this->seedMockBank(),
            'counterparty_name' => 'X',
            'action' => TransactionStatus::Approved->value,
        ]);

        (new PayeeRuleRecorder)->delete($rule);

        $this->assertSame(0, PayeeRule::query()->count());
    }

    private function seedMockBank(): string
    {
        if (! Bank::query()->where('slug', 'mock')->exists()) {
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

        return 'mock';
    }

    private function seedAccount(): BankAccount
    {
        $this->seedMockBank();

        return BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    private function seedTransaction(?string $name, int $level): Transaction
    {
        static $seq = 0;
        $seq++;

        $account = $this->seedAccount();

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
