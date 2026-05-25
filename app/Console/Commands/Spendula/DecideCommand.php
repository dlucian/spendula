<?php

namespace App\Console\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Locks\AdvisoryLock;
use App\Services\Locks\LockBusyException;
use App\Services\Review\PayeeRuleRecorder;
use App\Services\Review\RecordResult;
use App\Services\Review\TransactionActions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:decide
    {txn_id : Transaction UUID to decide.}
    {action : approve|skip|transfer}
    {--reason= : Skip reason. Only valid with action=skip.}
    {--remember : Persist a payee rule mirroring this decision.}')]
#[Description('Apply a single review decision to one transaction (non-interactive sibling of spendula:review).')]
class DecideCommand extends Command
{
    /**
     * Apply one review decision to one transaction and exit.
     *
     * Success: the transaction's status is updated to the requested action.
     *   If --remember is passed and the payee rule guards allow it, exactly
     *   one payee_rules row is inserted. Prints
     *   "Decided <uuid>: <action> (rule recorded: yes|no)" and exits 0.
     *
     * Failure: exits non-zero with an error on stderr when:
     *   - action is not one of {approve, skip, transfer}
     *   - --reason is paired with a non-skip action
     *   - the transaction is not found
     *   - the transaction is not in `fetched` status
     *   - the REVIEW advisory lock is held by another process
     *
     * Side effects: writes to `transactions` (status, skipped_at,
     *   skip_reason). Optionally inserts into `payee_rules`. No HTTP calls.
     *
     * Idempotency: NOT safe to retry — a second call on the same row will
     *   fail with "not in fetched status".
     *
     * Concurrency: acquires AdvisoryLock::REVIEW for the duration of the
     *   decision. Contends with interactive spendula:review sessions.
     */
    public function handle(TransactionActions $actions, PayeeRuleRecorder $recorder): int
    {
        $id = (string) $this->argument('txn_id');
        $action = (string) $this->argument('action');
        $reason = $this->option('reason');
        $reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : null;
        $remember = (bool) $this->option('remember');

        if (! in_array($action, ['approve', 'skip', 'transfer'], true)) {
            $this->error("Unknown action '{$action}'. Expected: approve|skip|transfer.");

            return self::INVALID;
        }

        if ($reason !== null && $action !== 'skip') {
            $this->error('--reason is only valid with action=skip.');

            return self::INVALID;
        }

        try {
            return AdvisoryLock::withLock(
                AdvisoryLock::REVIEW,
                function () use ($id, $action, $reason, $remember, $actions, $recorder): int {
                    $tx = Transaction::query()->with('bankAccount')->find($id);

                    if ($tx === null) {
                        $this->error("Transaction {$id} not found.");

                        return self::FAILURE;
                    }

                    if ($tx->status !== TransactionStatus::Fetched) {
                        $this->error("Transaction {$id} is in status '{$tx->status->value}', not 'fetched'; refusing to decide.");

                        return self::FAILURE;
                    }

                    match ($action) {
                        'approve' => $actions->approve($tx),
                        'skip' => $actions->skip($tx, $reason),
                        'transfer' => $actions->markTransfer($tx),
                    };

                    $ruleRecorded = 'no';
                    if ($remember) {
                        $statusEnum = $this->actionToStatus($action);
                        $result = $recorder->record($tx, $statusEnum, $reason);
                        if ($result === RecordResult::Created) {
                            $ruleRecorded = 'yes';
                        }
                    }

                    $this->info("Decided {$id}: {$action} (rule recorded: {$ruleRecorded})");

                    return self::SUCCESS;
                }
            );
        } catch (LockBusyException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function actionToStatus(string $action): TransactionStatus
    {
        return match ($action) {
            'approve' => TransactionStatus::Approved,
            'skip' => TransactionStatus::Skipped,
            'transfer' => TransactionStatus::Transfer,
            default => throw new \LogicException("Unreachable: action '{$action}' already validated."),
        };
    }
}
