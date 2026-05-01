<?php

namespace Tests\Feature\Services\Review;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Review\ReviewSession;
use App\Services\Review\TransactionActions;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Defends the structural exclusion of `status = tracking` rows from the
 * review queue (SPEC §5.3 / §6.5). ReviewSession filters by
 * `status = fetched`, so tracking rows are dropped by construction; this
 * test guards against an accidental relaxation of that filter.
 *
 * Runs against the non-TTY branch of `ReviewSession::run()`
 * (`app()->runningUnitTests()` short-circuits the raw-mode loop) and asserts
 * on the `{N} transaction(s) awaiting review` warning, which reflects the
 * size of the loaded queue.
 */
class ReviewSessionTest extends TestCase
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

    public function test_queue_excludes_tracking_status_rows(): void
    {
        $this->seedTransaction('ref-fetched', TransactionStatus::Fetched);
        $this->seedTransaction('ref-tracking', TransactionStatus::Tracking);

        $this->artisan('spendula:review')
            ->expectsOutputToContain('1 transaction(s) awaiting review')
            ->doesntExpectOutput('2 transaction(s) awaiting review')
            ->assertSuccessful();

        // Both rows untouched: review never approves/skips/transfers in a
        // non-TTY run, but the assertion is here to keep the test honest if
        // the structural exclusion ever flips.
        $this->assertSame(
            TransactionStatus::Tracking,
            Transaction::query()->where('entry_reference', 'ref-tracking')->sole()->status,
        );
        $this->assertSame(
            TransactionStatus::Fetched,
            Transaction::query()->where('entry_reference', 'ref-fetched')->sole()->status,
        );
    }

    public function test_approve_then_undo_reverts_status_and_decrements_counters(): void
    {
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);

        // Sequence: a (approve a), u (undo a — re-displays a), a (approve a),
        // a (approve b), q (exit tail prompt). The trailing `q` is now
        // required because draining the queue with a non-empty undo stack
        // enters the tail-prompt loop (per finding #2: undo must be
        // reachable for the last-decided row).
        $command = $this->makeCommand();
        $reader = $this->keyReader(['a', 'u', 'a', 'a', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $b->refresh();
        $this->assertSame(TransactionStatus::Approved, $a->status);
        $this->assertSame(TransactionStatus::Approved, $b->status);
        $this->assertSame(2, $stats['approved']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['transferred']);
        $this->assertSame(2, $stats['reviewed']);
        $this->assertTrue($stats['quit'], 'tail-prompt q sets quit=true');
    }

    public function test_undo_after_approve_clears_state_and_decrements_counters(): void
    {
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        // Need a second row so that after deciding `a`, the loop has a
        // current row to display when `u` is pressed.
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);

        // Press: a (decides $a), u (undoes $a; queue head now $a then $b), q
        $command = $this->makeCommand();
        $reader = $this->keyReader(['a', 'u', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $b->refresh();
        $this->assertSame(TransactionStatus::Fetched, $a->status);
        $this->assertSame(TransactionStatus::Fetched, $b->status);
        $this->assertNull($a->skipped_at);
        $this->assertNull($a->skip_reason);
        $this->assertSame(0, $stats['approved']);
        $this->assertSame(0, $stats['reviewed']);
        $this->assertTrue($stats['quit']);
    }

    public function test_undo_after_skip_clears_skip_reason_and_decrements_skipped_counter(): void
    {
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);

        $command = $this->makeCommand(['wrong reason']);
        // 's' triggers ask() inline; then 'u' undoes; then 'q' quits.
        $reader = $this->keyReader(['s', 'u', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $this->assertSame(TransactionStatus::Fetched, $a->status);
        $this->assertNull($a->skipped_at);
        $this->assertNull($a->skip_reason);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['reviewed']);
    }

    public function test_undo_after_transfer_reverts_status_and_decrements_transferred(): void
    {
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);

        $command = $this->makeCommand();
        $reader = $this->keyReader(['t', 'u', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $this->assertSame(TransactionStatus::Fetched, $a->status);
        $this->assertSame(0, $stats['transferred']);
        $this->assertSame(0, $stats['reviewed']);
    }

    public function test_undo_with_empty_stack_prints_nothing_to_undo_and_does_not_write_db(): void
    {
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);

        $command = $this->makeCommand();
        $output = $this->bufferedOutputFor($command);
        $reader = $this->keyReader(['u', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $this->assertSame(TransactionStatus::Fetched, $a->status);
        $this->assertSame(0, $stats['reviewed']);
        $this->assertStringContainsString('Nothing to undo.', $output->fetch());
    }

    public function test_three_undos_after_a_s_t_revert_in_lifo_order(): void
    {
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);
        $c = $this->seedTransaction('ref-c', TransactionStatus::Fetched, 3);
        // 4th row keeps a "current row" displayed while we issue the
        // three undos (the inner-most undo needs the queue non-empty).
        $d = $this->seedTransaction('ref-d', TransactionStatus::Fetched, 4);

        // Decide a=approve, b=skip(blank reason), c=transfer; then u,u,u to
        // revert in LIFO (transfer→skip→approve); then q.
        $command = $this->makeCommand(['']);
        $output = $this->bufferedOutputFor($command);
        $reader = $this->keyReader(['a', 's', 't', 'u', 'u', 'u', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $b->refresh();
        $c->refresh();
        $this->assertSame(TransactionStatus::Fetched, $a->status, 'a should be reverted');
        $this->assertSame(TransactionStatus::Fetched, $b->status, 'b should be reverted');
        $this->assertSame(TransactionStatus::Fetched, $c->status, 'c should be reverted');
        $this->assertSame(TransactionStatus::Fetched, $d->refresh()->status);
        $this->assertSame(0, $stats['approved']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['transferred']);
        $this->assertSame(0, $stats['reviewed']);

        // Prompt-sequence order assertion: each iteration prints a header
        // containing the row's `entry_ref=…`. The expected sequence is
        // a, b, c, d (initial decisions on a/s/t plus stepping onto d), then
        // c, b, a (LIFO undos: each undo re-queues the just-undone row as
        // current, so the next iteration redisplays it before stepping back
        // onto its predecessor's undo).
        $refs = [];
        foreach (explode("\n", $output->fetch()) as $line) {
            if (preg_match('/entry_ref=(ref-[a-d])/', $line, $m) === 1) {
                $refs[] = $m[1];
            }
        }
        $this->assertSame(
            ['ref-a', 'ref-b', 'ref-c', 'ref-d', 'ref-c', 'ref-b', 'ref-a'],
            $refs,
            'After three LIFO undos, the operator should see headers in order: a,b,c,d (decisions), then c,b,a (undos restoring 3rd, 2nd, 1st).',
        );
    }

    public function test_undo_works_on_last_row_via_tail_prompt(): void
    {
        // 1-row queue: deciding `a` drains the queue. Without the tail
        // prompt the operator could not undo the most recent decision at
        // all. Sequence: a (approves), u (tail-undo), a (approves again), q.
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);

        $command = $this->makeCommand();
        $output = $this->bufferedOutputFor($command);
        $reader = $this->keyReader(['a', 'u', 'a', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $this->assertSame(TransactionStatus::Approved, $a->status);
        $this->assertSame(1, $stats['approved']);
        $this->assertSame(1, $stats['reviewed']);
        $this->assertStringContainsString('Queue empty.', $output->fetch());
    }

    public function test_tail_undo_after_full_queue_drain_then_quit(): void
    {
        // 2-row queue, decide both, then undo the last one and quit.
        // Acceptance criterion: "q after some decisions and undos exits
        // cleanly; the session summary reflects the post-undo counters."
        // Without the tail prompt this scenario was unreachable.
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);

        $command = $this->makeCommand();
        $reader = $this->keyReader(['a', 'a', 'u', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $b->refresh();
        $this->assertSame(TransactionStatus::Approved, $a->status);
        $this->assertSame(TransactionStatus::Fetched, $b->status, 'b should be reverted by tail-undo');
        $this->assertSame(1, $stats['approved']);
        $this->assertSame(1, $stats['reviewed']);
        $this->assertTrue($stats['quit']);
    }

    public function test_undo_refuses_to_revert_a_row_pushed_in_another_session(): void
    {
        // Race: review and push hold different advisory locks. Operator
        // approves row a, then `spendula:push` runs in another terminal
        // and promotes a to `pushed` (with ynab_transaction_id and
        // pushed_at). When the operator presses `u`, undo must NOT
        // blindly clear status to `fetched` — that would leave stale
        // ynab_transaction_id/pushed_at, and PushRunner only selects
        // rows where ynab_transaction_id IS NULL, so on re-approve the
        // row would never be re-pushed. Expected behaviour: warn, drop
        // the entry from the stack, leave DB and counters untouched.
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);

        $command = $this->makeCommand();
        $output = $this->bufferedOutputFor($command);

        // Custom key reader that simulates an external push between the
        // operator's `a` and `u` keystrokes.
        $keys = ['a', 'u', 'q'];
        $i = 0;
        $reader = function () use (&$i, &$keys, $a): string {
            $key = $keys[$i] ?? 'q';
            if (($keys[$i] ?? null) === 'u') {
                // Mutate via DB::table to bypass any model events / casts —
                // mirrors what spendula:push does at its own write path.
                DB::table('transactions')
                    ->where('id', $a->id)
                    ->update([
                        'status' => TransactionStatus::Pushed->value,
                        'ynab_transaction_id' => 'ynab-tx-1',
                        'pushed_at' => Carbon::now(),
                    ]);
            }
            $i++;

            return $key;
        };

        $session = new ReviewSession($command, new TransactionActions, $reader);
        $stats = $session->run();

        $a->refresh();
        $this->assertSame(TransactionStatus::Pushed, $a->status, 'a must remain pushed — undo should refuse');
        $this->assertSame('ynab-tx-1', $a->ynab_transaction_id, 'push metadata must remain intact');
        $this->assertNotNull($a->pushed_at);

        // Counter stays at 1 because the original `a` decision still stands
        // (the row was approved this session and then pushed externally).
        $this->assertSame(1, $stats['approved']);
        $this->assertSame(1, $stats['reviewed']);

        $rendered = $output->fetch();
        $this->assertStringContainsString('cannot undo', $rendered);
        $this->assertStringContainsString('pushed', $rendered);
    }

    public function test_quit_after_decisions_and_undo_returns_post_undo_counters(): void
    {
        $a = $this->seedTransaction('ref-a', TransactionStatus::Fetched, 1);
        $b = $this->seedTransaction('ref-b', TransactionStatus::Fetched, 2);
        // 3rd row keeps a current row available while we undo b's approval.
        $c = $this->seedTransaction('ref-c', TransactionStatus::Fetched, 3);

        // a=approve a, b=approve b, then on c press u→undo b's approve, q.
        $command = $this->makeCommand();
        $reader = $this->keyReader(['a', 'a', 'u', 'q']);
        $session = new ReviewSession($command, new TransactionActions, $reader);

        $stats = $session->run();

        $a->refresh();
        $b->refresh();
        $this->assertSame(TransactionStatus::Approved, $a->status);
        $this->assertSame(TransactionStatus::Fetched, $b->status);
        $this->assertSame(1, $stats['approved']);
        $this->assertSame(1, $stats['reviewed']);
        $this->assertTrue($stats['quit']);
    }

    /**
     * @param  list<string>  $askResponses  Sequenced responses for `Command::ask()` calls (used by skip prompts).
     */
    private function makeCommand(array $askResponses = []): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'review-session-test:fake';
        };

        $input = new ArrayInput([]);
        $input->setInteractive(false);
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
        // OutputStyle (extends SymfonyStyle) wraps the underlying output as $output.
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
                // Safety: feed 'q' to ensure the loop terminates if a test
                // under-supplies keys, instead of hanging.
                return 'q';
            }

            return $keys[$i++];
        };
    }

    private function seedTransaction(string $entryRef, TransactionStatus $status, int $seq = 0): Transaction
    {
        if ($seq === 0) {
            static $auto = 0;
            $auto++;
            $seq = $auto;
        }

        return Transaction::query()->create([
            'bank_account_id' => $this->account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => $entryRef,
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => 'Coffee Shop',
            'counterparty_resolution_level' => 0,
            'occurrence' => 1,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
