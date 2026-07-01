<?php

namespace App\Console\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Locks\AdvisoryLock;
use App\Services\Locks\LockBusyException;
use App\Services\Review\PayeeRuleEngine;
use App\Services\Review\PayeeRuleRecorder;
use App\Services\Review\ReviewSession;
use App\Services\Review\TransactionActions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:review {--bulk-approve-trivial : Auto-approve rows whose resolution level ≤ 1 and currency matches SPENDULA_BASE_CURRENCY} {--approve-all : Auto-approve every fetched row (for unattended cron sync→review→push; YNAB becomes the approval gate)}')]
#[Description('Interactive CLI queue: Approve / Skip / Transfer fetched transactions.')]
class ReviewCommand extends Command
{
    public function handle(
        TransactionActions $actions,
        PayeeRuleEngine $ruleEngine,
        PayeeRuleRecorder $ruleRecorder,
    ): int {
        try {
            return AdvisoryLock::withLock(
                AdvisoryLock::REVIEW,
                function () use ($actions, $ruleEngine, $ruleRecorder): int {
                    // GH #39 — auto-apply rules BEFORE the interactive loop
                    // so matched rows leave the `fetched` pool and don't
                    // re-prompt the operator. Also runs BEFORE
                    // --bulk-approve-trivial so an explicit operator-
                    // authored rule (e.g. `skipped` for some payee) wins
                    // over the heuristic.
                    //
                    // Apply when the session is interactive OR the operator
                    // explicitly opted into mutation via --bulk-approve-trivial.
                    // Plain non-TTY review (no flag) stays side-effect-free
                    // so cron/probe usage doesn't silently mutate state.
                    // (Round-1 codex P1 + round-4 codex P1 reconciled: rules
                    // must run whenever any auto-mutation can run, but plain
                    // non-TTY remains a no-op.)
                    $isInteractive = $this->isInteractiveSession();
                    $bulkApproveTrivial = (bool) $this->option('bulk-approve-trivial');
                    $approveAll = (bool) $this->option('approve-all');
                    $autoApplied = ['appliedIds' => [], 'byAction' => ['approved' => 0, 'skipped' => 0, 'transferred' => 0]];
                    if ($isInteractive || $bulkApproveTrivial || $approveAll) {
                        $queue = Transaction::query()
                            ->where('status', TransactionStatus::Fetched->value)
                            ->with('bankAccount')
                            ->orderBy('bank_account_id')
                            ->orderBy('booking_date')
                            ->orderBy('occurrence')
                            ->get();
                        $autoApplied = $ruleEngine->applyRules($queue);
                    }

                    if ($bulkApproveTrivial) {
                        $baseCurrency = (string) config('spendula.base_currency', 'EUR');
                        $approved = $actions->bulkApproveTrivial($baseCurrency);
                        $this->info("Bulk-approved {$approved} trivial transaction(s) in {$baseCurrency}.");
                    }

                    // GH #22 — --approve-all runs AFTER auto-applied rules so
                    // operator-authored skip/transfer rules (and the own-account
                    // classifier) still win: those rows have already left the
                    // fetched pool and are not swept into `approved`.
                    //
                    // This is the unattended (cron) path: once every remaining
                    // fetched row is approved there is nothing left to review,
                    // so we short-circuit BEFORE ReviewSession. Running it would
                    // print "Nothing to review — the fetched queue is empty."
                    // and then a "Reviewed 0: approved=0 …" summary that
                    // contradicts the rows we just approved. The single line
                    // here (plus the rules breakdown when non-empty) is the
                    // whole report.
                    if ($approveAll) {
                        $approved = $actions->bulkApproveAll();
                        $this->info("Approved {$approved} fetched transaction(s).");

                        $ruleCounts = $this->recomputeAutoApplyByAction($autoApplied['appliedIds']);
                        if (array_sum($ruleCounts) > 0) {
                            $this->line(sprintf(
                                '  Payee rules also applied: approved=%d skipped=%d transferred=%d',
                                $ruleCounts['approved'],
                                $ruleCounts['skipped'],
                                $ruleCounts['transferred'],
                            ));
                        }

                        return self::SUCCESS;
                    }

                    $session = new ReviewSession($this, $actions, recorder: $ruleRecorder);
                    $stats = $session->run($autoApplied['appliedIds'], $autoApplied['byAction']);

                    // Round-3 codex P2 — fold auto-apply into the
                    // summary. Round-4 codex P2 — recompute auto-applied
                    // counts from the FINAL row status, since the
                    // override sub-loop may have flipped some of them
                    // (e.g. auto-approved → skipped). Without this, the
                    // summary keeps the original auto-apply tally and
                    // mis-reports the final state.
                    $finalAutoByAction = $this->recomputeAutoApplyByAction($autoApplied['appliedIds']);
                    $this->newLine();
                    $this->info(sprintf(
                        'Reviewed %d: approved=%d skipped=%d transferred=%d%s',
                        $stats['reviewed'] + count($autoApplied['appliedIds']),
                        $stats['approved'] + $finalAutoByAction['approved'],
                        $stats['skipped'] + $finalAutoByAction['skipped'],
                        $stats['transferred'] + $finalAutoByAction['transferred'],
                        $stats['quit'] ? ' (quit early)' : '',
                    ));

                    return self::SUCCESS;
                }
            );
        } catch (LockBusyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Mirror of ReviewSession::stdinIsTty() for the auto-apply gate.
     * Duplicated rather than exposed because the session uses an
     * injected key-reader closure (tests) to bypass the TTY check —
     * that pathway has no equivalent at the command layer, so the
     * command's notion of "interactive" is strictly the OS TTY.
     */
    /**
     * Re-derive how the auto-applied set ended up after the override
     * sub-loop. Rows that the operator flipped to a different action
     * land in a different bucket; rows that advanced to `pushed` (push
     * race in another session) or `fetched` (impossible from this
     * pipeline, but tolerated) drop out entirely.
     *
     * @param  list<string>  $autoAppliedIds
     * @return array{approved: int, skipped: int, transferred: int}
     */
    private function recomputeAutoApplyByAction(array $autoAppliedIds): array
    {
        $byAction = ['approved' => 0, 'skipped' => 0, 'transferred' => 0];
        if ($autoAppliedIds === []) {
            return $byAction;
        }
        // pluck('status') applies the model's enum cast, so it yields
        // TransactionStatus instances (Laravel 13). Older code here assumed
        // raw strings and matched on ->value, which silently bucketed
        // nothing once the cast kicked in. Normalise to the backing value so
        // both a cast enum and a raw string are counted (GH #22).
        $statuses = Transaction::query()
            ->whereIn('id', $autoAppliedIds)
            ->pluck('status');
        foreach ($statuses as $status) {
            $value = $status instanceof TransactionStatus ? $status->value : $status;
            match ($value) {
                TransactionStatus::Approved->value => $byAction['approved']++,
                TransactionStatus::Skipped->value => $byAction['skipped']++,
                TransactionStatus::Transfer->value => $byAction['transferred']++,
                default => null,
            };
        }

        return $byAction;
    }

    private function isInteractiveSession(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }
        if (! defined('STDIN')) {
            return false;
        }

        return stream_isatty(STDIN);
    }
}
