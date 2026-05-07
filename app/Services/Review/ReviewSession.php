<?php

namespace App\Services\Review;

use App\Enums\TransactionStatus;
use App\Models\PayeeRule;
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
        private readonly ?PayeeRuleRecorder $recorder = null,
    ) {
        $this->keyReader = $keyReader;
    }

    /**
     * Run the interactive review loop. When `$autoAppliedIds` is non-empty,
     * the loop first prints a summary line and offers the operator a
     * "show details? [y/N]" prompt; answering yes opens an override
     * sub-loop over the auto-applied rows where the operator can flip
     * any decision and (optionally) update or delete the underlying
     * payee_rules entry.
     *
     * Each interactive decision in the main loop also calls
     * `PayeeRuleRecorder::record()` so future syncs can auto-apply the
     * same verdict — gated by the recorder's denylist + resolution-level
     * guards. Mass-approved rows from `--bulk-approve-trivial` are NOT
     * recorded (they bypass this method entirely).
     *
     * @param  list<string>  $autoAppliedIds
     * @param  array{approved: int, skipped: int, transferred: int}  $autoByAction
     * @return array{reviewed: int, approved: int, skipped: int, transferred: int, quit: bool}
     */
    public function run(array $autoAppliedIds = [], array $autoByAction = ['approved' => 0, 'skipped' => 0, 'transferred' => 0]): array
    {
        if ($autoAppliedIds !== [] && $this->stdinIsTty()) {
            $this->command->getOutput()->writeln(sprintf(
                'Auto-applied: %d approved, %d skipped, %d transferred.',
                $autoByAction['approved'],
                $autoByAction['skipped'],
                $autoByAction['transferred'],
            ));
            $this->command->getOutput()->write('Show details? [y/N] ');

            $this->enterRawMode();
            try {
                $reply = strtolower($this->readKey());
            } finally {
                $this->leaveRawMode();
            }
            $this->command->getOutput()->writeln('');

            if ($reply === 'y') {
                $overrideStats = $this->runOverrideLoop($autoAppliedIds);
                if ($overrideStats['quit']) {
                    return [
                        'reviewed' => 0,
                        'approved' => 0,
                        'skipped' => 0,
                        'transferred' => 0,
                        'quit' => true,
                    ];
                }
            }
        }

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
         * Each entry: ['transaction' => Transaction, 'statKey' => 'approved'|'skipped'|'transferred', 'createdRuleId' => ?string].
         * `createdRuleId` is the UUID of the payee_rules row that was inserted
         * by this decision (null when the recorder declined to create one,
         * e.g. AlreadyExists or SkippedByGuard, or when no recorder is wired).
         *
         * @var list<array{transaction: Transaction, statKey: string, createdRuleId: ?string}> $undoStack
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
                                $createdRuleId = $this->recordAndCaptureRuleId(
                                    $transaction,
                                    TransactionStatus::Approved,
                                    null,
                                );
                                $undoStack[] = [
                                    'transaction' => $transaction,
                                    'statKey' => 'approved',
                                    'createdRuleId' => $createdRuleId,
                                ];
                                $stats['approved']++;
                                $stats['reviewed']++;
                                $keep = false;
                                break;

                            case 's':
                                $this->leaveRawMode();
                                $reason = (string) $this->command->ask('Skip reason (blank = none)', '');
                                $this->enterRawMode();
                                $skipReason = $reason !== '' ? $reason : null;
                                $this->actions->skip($transaction, $skipReason);
                                $createdRuleId = $this->recordAndCaptureRuleId(
                                    $transaction,
                                    TransactionStatus::Skipped,
                                    $skipReason,
                                );
                                $undoStack[] = [
                                    'transaction' => $transaction,
                                    'statKey' => 'skipped',
                                    'createdRuleId' => $createdRuleId,
                                ];
                                $stats['skipped']++;
                                $stats['reviewed']++;
                                $keep = false;
                                break;

                            case 't':
                                $this->actions->markTransfer($transaction);
                                $createdRuleId = $this->recordAndCaptureRuleId(
                                    $transaction,
                                    TransactionStatus::Transfer,
                                    null,
                                );
                                $undoStack[] = [
                                    'transaction' => $transaction,
                                    'statKey' => 'transferred',
                                    'createdRuleId' => $createdRuleId,
                                ];
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
                        // Tail-prompt quit: the queue is already drained, so
                        // this is a normal exit, not an early bail-out. Don't
                        // flag $stats['quit'].
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
     * Mutates `$undoStack` and `$stats` only on a successful revert. When
     * a successful revert happens AND the original decision created a
     * payee_rules row, that rule is also hard-deleted — otherwise an
     * undone first-time decision would leave a stale rule that
     * auto-applies to future transactions even though the operator
     * corrected themselves (GH #39 codex finding P1).
     *
     * @param  list<array{transaction: Transaction, statKey: string, createdRuleId: ?string}>  $undoStack
     * @param  array{reviewed:int,approved:int,skipped:int,transferred:int,quit:bool}  $stats
     */
    private function popAndRevert(array &$undoStack, array &$stats): ?Transaction
    {
        if (empty($undoStack)) {
            $this->command->getOutput()->writeln('Nothing to undo.');

            return null;
        }

        /** @var array{transaction: Transaction, statKey: 'approved'|'skipped'|'transferred', createdRuleId: ?string} $entry */
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

        if ($entry['createdRuleId'] !== null && $this->recorder !== null) {
            $rule = PayeeRule::query()->whereKey($entry['createdRuleId'])->first();
            if ($rule !== null) {
                $this->recorder->delete($rule);
            }
        }

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

    /**
     * Override sub-loop (GH #39): walks every auto-applied transaction
     * and offers a/s/t/d/q. A different action than the rule's current
     * action triggers the conflict prompt: update / delete / keep
     * (one-off). The prompt is the only place a rule can be revised
     * without going through `spendula:rules:delete` + a fresh decision.
     *
     * Returns `quit=true` only when the operator pressed `q`. The main
     * caller treats that as a session-wide quit so the interactive loop
     * doesn't continue after the operator has bailed out.
     *
     * @param  list<string>  $autoAppliedIds
     * @return array{quit: bool}
     */
    private function runOverrideLoop(array $autoAppliedIds): array
    {
        if ($this->recorder === null) {
            return ['quit' => false];
        }

        /** @var Collection<int, Transaction> $rows */
        $rows = Transaction::query()
            ->whereIn('id', $autoAppliedIds)
            ->with('bankAccount.bank')
            ->orderBy('bank_account_id')
            ->orderBy('booking_date')
            ->orderBy('occurrence')
            ->get();

        $total = $rows->count();
        $position = 0;

        $this->enterRawMode();
        try {
            foreach ($rows as $transaction) {
                $position++;
                $this->printHeader($transaction, $position, $total);
                $this->command->getOutput()->writeln(sprintf(
                    'Auto-applied: %s. Override?',
                    $transaction->status->value,
                ));

                $keep = true;
                while ($keep) {
                    $this->command->getOutput()->write('[a]pprove  [s]kip  [t]ransfer  [k]eep  [d]etails  [q]uit > ');
                    $key = strtolower($this->readKey());
                    $this->command->getOutput()->writeln('');

                    switch ($key) {
                        case 'a':
                            $this->overrideTo($transaction, TransactionStatus::Approved, null);
                            $keep = false;
                            break;

                        case 's':
                            $this->leaveRawMode();
                            $reason = (string) $this->command->ask('Skip reason (blank = none)', '');
                            $this->enterRawMode();
                            $this->overrideTo($transaction, TransactionStatus::Skipped, $reason !== '' ? $reason : null);
                            $keep = false;
                            break;

                        case 't':
                            $this->overrideTo($transaction, TransactionStatus::Transfer, null);
                            $keep = false;
                            break;

                        case 'k':
                            // Keep the auto-applied verdict and the rule unchanged.
                            $keep = false;
                            break;

                        case 'd':
                            $this->printDetails($transaction);
                            break;

                        case 'q':
                            return ['quit' => true];

                        default:
                            break;
                    }
                }
            }
        } finally {
            $this->leaveRawMode();
        }

        return ['quit' => false];
    }

    /**
     * Apply an override action to `$transaction`, then prompt the
     * operator about the rule that auto-applied to it: update the rule
     * to the new action, delete the rule (decide each time going
     * forward), or keep the rule as-is (treat this transaction as a
     * one-off override).
     */
    private function overrideTo(Transaction $transaction, TransactionStatus $newAction, ?string $skipReason): void
    {
        if ($this->recorder === null) {
            return;
        }

        // GH #39 codex finding P2 — push runs under a different advisory
        // lock, so an auto-applied row may have advanced to `pushed`
        // while the operator was sitting in the override sub-loop.
        // Refuse to override if the row is no longer at one of the
        // post-decision states; otherwise we would resurrect a pushed
        // row to `approved`/`transfer`/`skipped`.
        $fresh = $transaction->fresh();
        if ($fresh === null) {
            return;
        }
        $expected = [TransactionStatus::Approved, TransactionStatus::Skipped, TransactionStatus::Transfer];
        if (! in_array($fresh->status, $expected, true)) {
            $this->command->getOutput()->writeln(sprintf(
                '⚠ cannot override: %s has advanced to %s (likely pushed in another session).',
                $fresh->id,
                $fresh->status->value,
            ));

            return;
        }

        match ($newAction) {
            TransactionStatus::Approved => $this->actions->approve($fresh),
            TransactionStatus::Skipped => $this->actions->skip($fresh, $skipReason),
            TransactionStatus::Transfer => $this->actions->markTransfer($fresh),
            default => null,
        };

        $rule = $this->recorder->findFor($fresh);
        if ($rule === null) {
            // Rare: rule was hand-deleted between auto-apply and the
            // override prompt. Nothing to update.
            return;
        }

        if ($rule->action === $newAction) {
            // Operator picked the same action the rule already prescribes.
            // Usually no conflict — confirming an auto-approve / auto-transfer
            // is a no-op for the rule. Exception: a skip whose reason differs
            // from the rule's stored reason. That's the only in-session path
            // to revise a skipped rule's reason, so route it through the
            // conflict prompt (round-2 codex finding P2).
            if ($newAction !== TransactionStatus::Skipped) {
                return;
            }
            // Normalise both sides the same way `TransactionActions::skip()`
            // and `PayeeRuleRecorder::record()` already do (trim → empty
            // becomes null), otherwise leading/trailing whitespace re-entry
            // spuriously triggers the conflict prompt (round-5 codex P3).
            if ($this->normalizeReason($skipReason) === $this->normalizeReason($rule->skip_reason)) {
                return;
            }
        }

        $this->command->getOutput()->writeln(sprintf(
            'Rule "%s → %s" currently auto-applies %s.',
            $rule->bank_slug,
            $rule->counterparty_name,
            $rule->action->value,
        ));

        $resolution = $this->promptRuleConflict();
        match ($resolution) {
            'u' => $this->recorder->update($rule, $newAction, $skipReason),
            'd' => $this->recorder->delete($rule),
            default => null, // 'k' or unknown → keep rule as-is.
        };
    }

    /**
     * Run `PayeeRuleRecorder::record()` and capture the id of the
     * newly-inserted rule (when one was inserted), for later cleanup
     * by `popAndRevert()`. Returns null when no recorder is wired,
     * when a rule already existed for the (bank, payee) pair, or when
     * a guard tripped — none of those cases should be undone.
     */
    private function recordAndCaptureRuleId(
        Transaction $transaction,
        TransactionStatus $action,
        ?string $skipReason,
    ): ?string {
        if ($this->recorder === null) {
            return null;
        }
        $result = $this->recorder->record($transaction, $action, $skipReason);
        if ($result !== RecordResult::Created) {
            return null;
        }

        // record() doesn't return the model itself, but the rule must
        // exist now — re-load by (bank_slug, counterparty_name) to
        // grab the id. One extra query per interactive decision is
        // negligible compared to the human's keypress latency.
        return $this->recorder->findFor($transaction)?->id;
    }

    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        $trimmed = trim($reason);

        return $trimmed === '' ? null : $trimmed;
    }

    private function promptRuleConflict(): string
    {
        while (true) {
            $this->command->getOutput()->write('[u]pdate rule  [d]elete rule  [k]eep rule (one-off) > ');
            $key = strtolower($this->readKey());
            $this->command->getOutput()->writeln('');
            if (in_array($key, ['u', 'd', 'k'], true)) {
                return $key;
            }
        }
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
