<?php

namespace Tests\Feature\Services\Review;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PayeeRule;
use App\Models\Transaction;
use App\Services\Review\PayeeRuleRecorder;
use App\Services\Review\ReviewSession;
use App\Services\Review\TransactionActions;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * GH #39 — auto-decision rule integration tests for ReviewSession.
 * Cover (a) recorder is invoked on each interactive a/s/t decision,
 * (b) summary line is shown only when auto-applied IDs were passed in,
 * (c) override sub-loop runs only when the operator answers 'y',
 * (d) override matching rule action does not prompt for conflict,
 * (e) override differing action prompts u/d/k each with the right effect.
 */
class ReviewSessionPayeeRulesTest extends TestCase
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

    public function test_interactive_approve_creates_payee_rule(): void
    {
        $tx = $this->seedTransaction('Spotify', level: 1);

        $session = new ReviewSession(
            $this->makeCommand(),
            new TransactionActions,
            $this->keyReader(['a', 'q']),
            new PayeeRuleRecorder,
        );
        $session->run();

        $this->assertSame(TransactionStatus::Approved, $tx->refresh()->status);
        $rule = PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail();
        $this->assertSame(TransactionStatus::Approved, $rule->action);
    }

    public function test_interactive_skip_records_rule_with_skip_reason(): void
    {
        $this->seedTransaction('FishyMerchant', level: 2);

        $session = new ReviewSession(
            $this->makeCommand(['fraud']),
            new TransactionActions,
            $this->keyReader(['s', 'q']),
            new PayeeRuleRecorder,
        );
        $session->run();

        $rule = PayeeRule::query()->where('counterparty_name', 'FishyMerchant')->firstOrFail();
        $this->assertSame(TransactionStatus::Skipped, $rule->action);
        $this->assertSame('fraud', $rule->skip_reason);
    }

    public function test_summary_only_appears_when_auto_applied_ids_provided(): void
    {
        // No fetched rows; just ensure the summary path is invoked when
        // autoAppliedIds is non-empty even if the queue is otherwise empty.
        $other = $this->seedTransaction('AlreadyApproved', level: 1, status: TransactionStatus::Approved);

        $command = $this->makeCommand();
        $session = new ReviewSession(
            $command,
            new TransactionActions,
            $this->keyReader(['n']),
            new PayeeRuleRecorder,
        );
        $session->run([$other->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $output = $this->bufferedOutputFor($command)->fetch();
        $this->assertStringContainsString('Auto-applied: 1 approved, 0 skipped, 0 transferred', $output);
        $this->assertStringContainsString('Show details? [y/N]', $output);
    }

    public function test_show_details_no_skips_override_loop(): void
    {
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);

        $command = $this->makeCommand();
        $session = new ReviewSession(
            $command,
            new TransactionActions,
            // 'n' to "show details?" — override loop must not run.
            $this->keyReader(['n']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $output = $this->bufferedOutputFor($command)->fetch();
        $this->assertStringNotContainsString('Override?', $output);
        $this->assertSame(TransactionStatus::Approved, $auto->refresh()->status);
    }

    public function test_override_keep_preserves_rule_and_state(): void
    {
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        $session = new ReviewSession(
            $this->makeCommand(),
            new TransactionActions,
            // 'y' (show details) → 'k' (keep auto verdict for this row).
            $this->keyReader(['y', 'k']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $this->assertSame(TransactionStatus::Approved, $auto->refresh()->status);
        $this->assertSame(1, PayeeRule::query()->count(), 'Rule must survive a [k]eep override.');
    }

    public function test_override_to_same_action_does_not_prompt_rule_conflict(): void
    {
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        $command = $this->makeCommand();
        // 'y' → 'a' (override to same action). No conflict prompt → next row → 'q'.
        $session = new ReviewSession(
            $command,
            new TransactionActions,
            $this->keyReader(['y', 'a']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $output = $this->bufferedOutputFor($command)->fetch();
        $this->assertStringNotContainsString('[u]pdate rule', $output);
        $this->assertSame(TransactionStatus::Approved, $auto->refresh()->status);
    }

    public function test_override_differs_then_update_rewrites_rule(): void
    {
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        // 'y' → 's' (skip instead) → ask reason 'wrong charge' → conflict → 'u' (update rule)
        $session = new ReviewSession(
            $this->makeCommand(['wrong charge']),
            new TransactionActions,
            $this->keyReader(['y', 's', 'u']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $rule = PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail();
        $this->assertSame(TransactionStatus::Skipped, $rule->action);
        $this->assertSame('wrong charge', $rule->skip_reason);
        $this->assertSame(TransactionStatus::Skipped, $auto->refresh()->status);
    }

    public function test_override_differs_then_delete_removes_rule(): void
    {
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        // 'y' → 't' (transfer) → conflict → 'd' (delete rule).
        $session = new ReviewSession(
            $this->makeCommand(),
            new TransactionActions,
            $this->keyReader(['y', 't', 'd']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $this->assertSame(0, PayeeRule::query()->count());
        $this->assertSame(TransactionStatus::Transfer, $auto->refresh()->status);
    }

    public function test_override_differs_then_update_flips_final_status_in_db(): void
    {
        // Round-4 codex P2 — verifies that an override flipping an
        // auto-approved row to `skipped` actually changes the row's
        // status in the DB so the command-level summary recompute picks
        // it up correctly.
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        // 'y' → 's' → reason 'changed mind' → 'u' update.
        $session = new ReviewSession(
            $this->makeCommand(['changed mind']),
            new TransactionActions,
            $this->keyReader(['y', 's', 'u']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $this->assertSame(TransactionStatus::Skipped, $auto->refresh()->status);
    }

    public function test_override_same_action_skip_with_new_reason_updates_rule(): void
    {
        // Round-2 codex finding P2: same-action override on a skipped
        // rule must update the rule when the reason changes — otherwise
        // there is no in-session path to revise a stale skip reason.
        $auto = $this->seedTransaction('FishyMerchant', level: 2, status: TransactionStatus::Skipped);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'FishyMerchant',
            'action' => TransactionStatus::Skipped->value,
            'skip_reason' => 'old reason',
        ]);

        // 'y' → 's' → ask reason 'new reason' → conflict (different reason) → 'u' update
        $session = new ReviewSession(
            $this->makeCommand(['new reason']),
            new TransactionActions,
            $this->keyReader(['y', 's', 'u']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 0, 'skipped' => 1, 'transferred' => 0]);

        $rule = PayeeRule::query()->where('counterparty_name', 'FishyMerchant')->firstOrFail();
        $this->assertSame('new reason', $rule->skip_reason);
        $this->assertSame('new reason', $auto->refresh()->skip_reason);
    }

    public function test_undoing_first_decision_removes_the_just_created_rule(): void
    {
        // Round-1 codex finding P1: undo must roll back the rule too,
        // otherwise a corrected first-time decision still leaves a
        // stale rule that will auto-apply on future syncs.
        $tx = $this->seedTransaction('Spotify', level: 1);

        $session = new ReviewSession(
            $this->makeCommand(),
            new TransactionActions,
            // 'a' (creates rule) → 'u' (undo, deletes rule) → 'q' tail prompt.
            $this->keyReader(['a', 'u', 'q']),
            new PayeeRuleRecorder,
        );
        $session->run();

        $this->assertSame(TransactionStatus::Fetched, $tx->refresh()->status);
        $this->assertSame(0, PayeeRule::query()->count(), 'Undoing a first-time decision must hard-delete the just-created rule.');
    }

    public function test_override_refuses_to_act_on_a_row_that_was_pushed_concurrently(): void
    {
        // Round-1 codex finding P2: push runs under a different lock;
        // an auto-applied row can advance to `pushed` while the operator
        // is sitting in the override sub-loop. Resurrecting it would
        // be a correctness bug.
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        // Simulate a concurrent push promoting the row to pushed.
        $auto->status = TransactionStatus::Pushed;
        $auto->ynab_transaction_id = 'ynab-fake-id';
        $auto->save();

        $command = $this->makeCommand();
        // 'y' → 's' (try to override approved→skipped). Should be refused.
        $session = new ReviewSession(
            $command,
            new TransactionActions,
            $this->keyReader(['y', 's']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $output = $this->bufferedOutputFor($command)->fetch();
        $this->assertStringContainsString('cannot override', $output);
        $this->assertSame(TransactionStatus::Pushed, $auto->refresh()->status);
        // Rule unchanged.
        $rule = PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail();
        $this->assertSame(TransactionStatus::Approved, $rule->action);
    }

    public function test_override_differs_then_keep_leaves_rule_intact_but_changes_row(): void
    {
        $auto = $this->seedTransaction('Spotify', level: 1, status: TransactionStatus::Approved);
        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Spotify',
            'action' => TransactionStatus::Approved->value,
        ]);

        // 'y' → 't' (transfer) → conflict → 'k' (keep rule).
        $session = new ReviewSession(
            $this->makeCommand(),
            new TransactionActions,
            $this->keyReader(['y', 't', 'k']),
            new PayeeRuleRecorder,
        );
        $session->run([$auto->id], ['approved' => 1, 'skipped' => 0, 'transferred' => 0]);

        $rule = PayeeRule::query()->where('counterparty_name', 'Spotify')->firstOrFail();
        $this->assertSame(TransactionStatus::Approved, $rule->action, 'Rule must remain unchanged on [k]eep.');
        $this->assertSame(TransactionStatus::Transfer, $auto->refresh()->status);
    }

    /**
     * @param  list<string>  $askResponses
     */
    private function makeCommand(array $askResponses = []): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'mock';

            public function handle(): int
            {
                return self::SUCCESS;
            }
        };

        $command->setHelperSet(new HelperSet([
            new QuestionHelper,
        ]));

        $input = new ArrayInput([]);
        if ($askResponses !== []) {
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open in-memory stream for stubbed ask() responses.');
            }
            foreach ($askResponses as $line) {
                fwrite($stream, $line."\n");
            }
            rewind($stream);
            $input->setStream($stream);
            $input->setInteractive(true);
        }

        $output = new BufferedOutput;
        $style = new OutputStyle($input, $output);

        $command->setLaravel(app());
        $command->setInput($input);
        $command->setOutput($style);

        return $command;
    }

    private function bufferedOutputFor(Command $command): BufferedOutput
    {
        $style = $command->getOutput();
        $reflection = new \ReflectionObject($style);
        $prop = $reflection->getProperty('output');
        $prop->setAccessible(true);
        $inner = $prop->getValue($style);
        if (! $inner instanceof BufferedOutput) {
            throw new \RuntimeException('Expected BufferedOutput inside OutputStyle.');
        }

        return $inner;
    }

    /**
     * @param  list<string>  $keys
     * @return \Closure():string
     */
    private function keyReader(array $keys): \Closure
    {
        $i = 0;

        return function () use (&$i, $keys): string {
            if ($i >= count($keys)) {
                return 'q';
            }

            return $keys[$i++];
        };
    }

    private function seedTransaction(
        string $name,
        int $level,
        TransactionStatus $status = TransactionStatus::Fetched,
    ): Transaction {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $this->account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => "ref-{$seq}",
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => $name,
            'counterparty_resolution_level' => $level,
            'occurrence' => 1,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
