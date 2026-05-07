<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PayeeRule;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReviewCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_approve_trivial_flag_applies_and_reports_count(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        // Two trivial, one level-2 (not trivial).
        $this->seedTransaction($account, level: 0, entryRef: 'ref-trivial-a');
        $this->seedTransaction($account, level: 1, entryRef: 'ref-trivial-b');
        $nonTrivial = $this->seedTransaction($account, level: 2, entryRef: 'ref-nontrivial');

        $this->artisan('spendula:review', ['--bulk-approve-trivial' => true])
            ->expectsOutputToContain('Bulk-approved 2 trivial transaction(s) in EUR.')
            ->assertSuccessful();

        $this->assertSame(2, Transaction::query()->where('status', TransactionStatus::Approved->value)->count());
        $this->assertSame(TransactionStatus::Fetched, $nonTrivial->refresh()->status);
    }

    public function test_plain_non_tty_invocation_does_not_auto_apply_payee_rules(): void
    {
        // Round-1 codex P1: plain `spendula:review` in non-TTY (cron,
        // piped) was side-effect-free pre-#39. Auto-apply must respect
        // that — rules only fire when the operator can see/override
        // them OR has explicitly opted into mutation via --bulk-approve-trivial.
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $tx = $this->seedTransaction($account, level: 1, entryRef: 'ref-rule-target');
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Coffee Shop',
            'action' => TransactionStatus::Skipped->value,
            'skip_reason' => 'unwanted',
        ]);

        $this->artisan('spendula:review')->assertSuccessful();

        $this->assertSame(TransactionStatus::Fetched, $tx->refresh()->status, 'Plain non-TTY auto-apply must stay a no-op.');
    }

    public function test_bulk_approve_trivial_honors_existing_payee_rules(): void
    {
        // Round-4 codex P1: --bulk-approve-trivial in non-TTY must
        // still let rules win precedence over the heuristic. A level-1
        // base-currency row covered by a `skipped` rule is auto-skipped,
        // not bulk-approved.
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');
        $ruleTarget = $this->seedTransaction($account, level: 1, entryRef: 'ref-rule-skipped');
        $heuristicTarget = $this->seedTransaction($account, level: 1, entryRef: 'ref-no-rule');
        $heuristicTarget->counterparty_name = 'No Rule Here';
        $heuristicTarget->save();
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Coffee Shop',
            'action' => TransactionStatus::Skipped->value,
            'skip_reason' => 'unwanted',
        ]);

        $this->artisan('spendula:review', ['--bulk-approve-trivial' => true])
            ->expectsOutputToContain('Bulk-approved 1 trivial transaction(s) in EUR.')
            ->assertSuccessful();

        $this->assertSame(TransactionStatus::Skipped, $ruleTarget->refresh()->status);
        $this->assertSame('unwanted', $ruleTarget->skip_reason);
        $this->assertSame(TransactionStatus::Approved, $heuristicTarget->refresh()->status);
    }

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

    private function seedTransaction(BankAccount $account, int $level, string $entryRef): Transaction
    {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => $entryRef,
            'status' => TransactionStatus::Fetched,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => $account->currency,
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => 'Coffee Shop',
            'counterparty_resolution_level' => $level,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
