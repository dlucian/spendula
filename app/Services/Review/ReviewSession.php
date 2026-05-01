<?php

namespace App\Services\Review;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Money\Money;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The interactive review loop (SPEC §7.1). Bootstrapped with raw-mode TTY
 * so single keypresses drive state transitions without requiring Enter.
 * The TTY mode is always restored in a finally block — leaving a terminal
 * in `-icanon -echo` is a painful footgun.
 *
 * This class is intentionally decoupled from Transaction's DB writes —
 * those live in TransactionActions so the business logic is testable
 * without a live stdin.
 *
 * The undo stack (`u`) is in-memory only and discarded on `q` or process
 * exit. Only interactive `a`/`s`/`t` decisions made within the current
 * session are reachable; rows mass-approved via `--bulk-approve-trivial`
 * are out of scope (they never had an interactive decision to undo).
 *
 * Undo is push-race-safe: review and push hold different advisory locks,
 * so `spendula:push` can promote an `approved`/`transfer` row to `pushed`
 * while review is still open. Each undo fresh-loads the row and refuses
 * to revert if its status has advanced past the post-decision state — see
 * `popAndRevert()`. After the queue drains, a tail-prompt loop offers a
 * final chance to undo the just-decided last row.
 *
 * For testing the keypress loop without a real TTY, the constructor
 * accepts an optional key reader closure that returns the next byte as
 * a string. Production callers omit it and the session reads from
 * `STDIN` via `fgetc`.
 */
class ReviewSession
{
    /**
     * @var Closure():string|null
     */
    private readonly ?Closure $keyReader;

    /**
     * @param  Closure():string|null  $keyReader  Optional injected reader returning the next keystroke as a string. Production omits; tests pass a fixture-driven closure.
     */
    public function __construct(
        private readonly Command $command,
        private readonly TransactionActions $actions,
        ?Closure $keyReader = null,
    ) {
        $this->keyReader = $keyReader;
    }

    /**
     * @return array{reviewed: int, approved: int, skipped: int, transferred: int, quit: bool}
     */
    public function run(): array
    {
        /** @var Collection<int, Transaction> $queue */
        $queue = Transaction::query()
            ->where('status', TransactionStatus::Fetched->value)
            ->with('bankAccount.bank')
            ->orderBy('bank_account_id')
            ->orderBy('booking_date')
            ->orderBy('occurrence')
            ->get();

        $total = $queue->count();
        if ($total === 0) {
            $this->command->info('Nothing to review — the fetched queue is empty.');

            return ['reviewed' => 0, 'approved' => 0, 'skipped' => 0, 'transferred' => 0, 'quit' => false];
        }

        if (! $this->stdinIsTty()) {
            // Non-interactive invocation (tests, piped input, CI). Nothing to read keypresses from.
            $this->command->warn("{$total} transaction(s) awaiting review; skipping interactive loop because stdin is not a TTY.");

            return ['reviewed' => 0, 'approved' => 0, 'skipped' => 0, 'transferred' => 0, 'quit' => false];
        }

        $stats = ['reviewed' => 0, 'approved' => 0, 'skipped' => 0, 'transferred' => 0, 'quit' => false];

        // Deque pump: undo pushes rows back to the front, so we cannot
        // foreach the eager collection. Keep an array of pending rows and
        // a 1-based position counter we increment per processed row.
        /** @var list<Transaction> $remaining */
        $remaining = $queue->all();
        $position = 0;

        /**
         * In-memory LIFO undo stack of decisions made in this session.
         * Each entry: ['transaction' => Transaction, 'statKey' => 'approved'|'skipped'|'transferred'].
         *
         * @var list<array{transaction: Transaction, statKey: string}> $undoStack
         */
        $undoStack = [];

        $this->enterRawMode();
        try {
            // Outer wrapper: the inner per-row loop drains `$remaining`, but
            // when it empties we still need to offer the operator one last
            // chance to undo (the just-decided row would otherwise be
            // unreachable for `u`). The tail-prompt block re-fills
            // `$remaining` on a successful undo and we re-enter the inner
            // loop; otherwise we exit cleanly.
            while (true) {
                while (! empty($remaining)) {
                    $transaction = array_shift($remaining);
                    $position++;

                    $this->printHeader($transaction, $position, $total);

                    $keep = true;
                    while ($keep) {
                        $this->command->getOutput()->write('[a]pprove  [s]kip  [t]ransfer  [u]ndo  [d]etails  [q]uit > ');
                        $key = strtolower($this->readKey());
                        $this->command->getOutput()->writeln(''); // newline after keypress echo

                        switch ($key) {
                            case 'a':
                                $this->actions->approve($transaction);
                                $undoStack[] = ['transaction' => $transaction, 'statKey' => 'approved'];
                                $stats['approved']++;
                                $stats['reviewed']++;
                                $keep = false;
                                break;

                            case 's':
                                $this->leaveRawMode();
                                $reason = (string) $this->command->ask('Skip reason (blank = none)', '');
                                $this->enterRawMode();
                                $this->actions->skip($transaction, $reason !== '' ? $reason : null);
                                $undoStack[] = ['transaction' => $transaction, 'statKey' => 'skipped'];
                                $stats['skipped']++;
                                $stats['reviewed']++;
                                $keep = false;
                                break;

                            case 't':
                                $this->actions->markTransfer($transaction);
                                $undoStack[] = ['transaction' => $transaction, 'statKey' => 'transferred'];
                                $stats['transferred']++;
                                $stats['reviewed']++;
                                $keep = false;
                                break;

                            case 'u':
                                $undoneTx = $this->popAndRevert($undoStack, $stats);
                                if ($undoneTx === null) {
                                    // Either stack empty or the row advanced
                                    // past its post-decision state (push race).
                                    // Stay on the same row; re-prompt.
                                    break;
                                }
                                // Push the currently-displayed (still-undecided)
                                // row back onto the front, then push the undone
                                // row in front of it. Decrement position by 2 so
                                // the next iteration re-shifts and re-increments
                                // to the correct (undone) row's position.
                                array_unshift($remaining, $transaction);
                                array_unshift($remaining, $undoneTx);
                                $position -= 2;
                                $keep = false;
                                break;

                            case 'd':
                                $this->printDetails($transaction);
                                // loop repeats, re-prompt
                                break;

                            case 'q':
                                $stats['quit'] = true;

                                return $stats;

                            default:
                                // ignore unknown keys, re-prompt
                                break;
                        }
                    }
                }

                // Tail-prompt: queue drained, but the operator may still want
                // to undo their most recent decision (or any earlier one in
                // LIFO order). Loop until either the stack drains, the
                // operator quits, or an undo refills `$remaining` (in which
                // case the outer wrapper re-enters the per-row inner loop).
                if (empty($undoStack)) {
                    break;
                }

                $this->command->getOutput()->writeln('');
                $this->command->getOutput()->write('Queue empty. [u]ndo last decision, [q]uit > ');
                $key = strtolower($this->readKey());
                $this->command->getOutput()->writeln('');

                switch ($key) {
                    case 'u':
                        $undoneTx = $this->popAndRevert($undoStack, $stats);
                        if ($undoneTx !== null) {
                            // No "currently-displayed" row exists in the tail
                            // state — only push the undone row. Decrement
                            // position by 1 so the next inner iteration
                            // re-increments to the row's original position.
                            array_unshift($remaining, $undoneTx);
                            $position--;
                        }
                        // Either way, re-enter the outer wrapper loop. If the
                        // undo failed (stack empty or push race) we'll fall
                        // through to the `empty($undoStack)` exit OR re-prompt
                        // the tail.
                        break;

                    case 'q':
                        $stats['quit'] = true;

                        return $stats;

                    default:
                        // ignore unknown keys, re-prompt the tail
                        break;
                }
            }
        } finally {
            $this->leaveRawMode();
        }

        return $stats;
    }

    /**
     * Pop the most recent decision off the undo stack and revert it. Returns
     * the freshly-loaded undone transaction on success, or null when:
     *   - the stack is empty (prints "Nothing to undo.");
     *   - the row's persisted status no longer matches its post-decision
     *     state (typically because `spendula:push` ran in another session
     *     and promoted an `approved`/`transfer` row to `pushed`). In that
     *     case the entry is dropped from the stack so subsequent `u`s walk
     *     to the next-most-recent decision; counters and DB are left
     *     untouched. PushRunner only selects rows where
     *     `ynab_transaction_id IS NULL`, so blindly clearing status to
     *     `fetched` here would leave stale push metadata that masks the
     *     row from re-push forever.
     *
     * Mutates `$undoStack` and `$stats` only on a successful revert.
     *
     * @param  list<array{transaction: Transaction, statKey: string}>  $undoStack
     * @param  array{reviewed:int,approved:int,skipped:int,transferred:int,quit:bool}  $stats
     */
    private function popAndRevert(array &$undoStack, array &$stats): ?Transaction
    {
        if (empty($undoStack)) {
            $this->command->getOutput()->writeln('Nothing to undo.');

            return null;
        }

        /** @var array{transaction: Transaction, statKey: 'approved'|'skipped'|'transferred'} $entry */
        $entry = array_pop($undoStack);
        $fresh = $entry['transaction']->fresh() ?? $entry['transaction'];

        $expectedStatus = match ($entry['statKey']) {
            'approved' => TransactionStatus::Approved,
            'skipped' => TransactionStatus::Skipped,
            'transferred' => TransactionStatus::Transfer,
        };

        if ($fresh->status !== $expectedStatus) {
            $this->command->getOutput()->writeln(sprintf(
                '⚠ cannot undo: %s has advanced to %s (likely pushed in another session).',
                $fresh->id,
                $fresh->status->value,
            ));

            return null;
        }

        $this->actions->revertToFetched($fresh);

        $undoLabel = match ($entry['statKey']) {
            'approved' => 'approved',
            'skipped' => 'skipped',
            'transferred' => 'transfer',
        };

        $stats[$entry['statKey']]--;
        $stats['reviewed']--;

        $this->command->getOutput()->writeln(sprintf(
            '↶ undid: %s %s→fetched',
            $fresh->id,
            $undoLabel,
        ));

        return $fresh;
    }

    private function printHeader(Transaction $transaction, int $position, int $total): void
    {
        $account = $transaction->bankAccount;
        $bank = $account !== null ? $account->bank : null;

        $currency = $transaction->currency;
        $amount = Money::format($transaction->amount_milliunits, $currency);
        $symbol = Money::symbol($currency);
        $signed = (str_starts_with($amount, '-') ? '−' : '+').$symbol.ltrim($amount, '-');

        $bankLabel = $bank !== null ? $bank->display_name : $transaction->bank_account_id;
        $accountLabel = ($account !== null && $account->display_name !== null) ? $account->display_name : '(unmapped)';

        $label = trim(sprintf('%s · %s · %s', $bankLabel, $accountLabel, $currency));

        $this->command->getOutput()->writeln('');
        $this->command->getOutput()->writeln(str_repeat('─', 60));
        $this->command->getOutput()->writeln(sprintf('[%d/%d]  %s', $position, $total, $label));
        $this->command->getOutput()->writeln(sprintf(
            '%s  %s  →  %s',
            $transaction->booking_date->toDateString(),
            $signed,
            $transaction->counterparty_name ?? '(unknown)',
        ));
        $this->command->getOutput()->writeln(sprintf(
            '        resolution level %d%s',
            $transaction->counterparty_resolution_level,
            $transaction->entry_reference !== null ? " · entry_ref={$transaction->entry_reference}" : '',
        ));
        $this->command->getOutput()->writeln(str_repeat('─', 24));
    }

    private function printDetails(Transaction $transaction): void
    {
        $this->leaveRawMode();
        $this->command->getOutput()->writeln('');
        $this->command->getOutput()->writeln((string) json_encode($transaction->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->command->getOutput()->writeln('');
        $this->enterRawMode();
    }

    private function readKey(): string
    {
        if ($this->keyReader !== null) {
            return ($this->keyReader)();
        }

        return (string) fgetc(STDIN);
    }

    private function enterRawMode(): void
    {
        if (! $this->stdinIsTty()) {
            return;
        }
        // `-icanon -echo` = no line buffering, no character echo. min 1 time 0 = fgetc blocks until one byte arrives.
        system('stty -icanon -echo min 1 time 0 2>/dev/null');
    }

    private function leaveRawMode(): void
    {
        if (! $this->stdinIsTty()) {
            return;
        }
        system('stty sane 2>/dev/null');
    }

    private function stdinIsTty(): bool
    {
        // An injected key reader bypasses the TTY check entirely — the
        // caller is supplying keystrokes programmatically, so the loop
        // must run regardless of whether STDIN happens to be a tty.
        if ($this->keyReader !== null) {
            return true;
        }

        // PHPUnit/Pest runners (especially watchers like phpunit-watcher) can
        // leave STDIN attached to a TTY and feed buffered keypresses into the
        // test process. Without this guard the review loop swallows whatever
        // bytes are in stdin and silently mutates seeded fixtures. App-level
        // testing flag is the most reliable signal — fall through to the
        // stream/posix check only outside the test environment.
        if (app()->runningUnitTests()) {
            return false;
        }

        if (! defined('STDIN')) {
            return false;
        }

        // stream_isatty is built into PHP 7.2+ (no extension required); the
        // production php:8.4-fpm-alpine image does not install ext-posix, so
        // posix_isatty would always return false and silently disable the
        // approval loop inside `docker compose exec -it`.
        return stream_isatty(STDIN);
    }
}
