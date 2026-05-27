<?php

namespace App\Console\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('spendula:accounts:deactivate
    {--id= : bank_accounts.id (UUID) to deactivate.}
    {--force : Proceed even if the account has approved/transfer transactions awaiting push. Those rows become dead — not pushable, not surfaced in spendula:status — until the account is reactivated.}
')]
#[Description('Deactivate a bank_account so spendula:sync stops attempting it, spendula:push stops sending its rows, and spendula:status stops surfacing them. Reversible via a one-line UPDATE.')]
class AccountsDeactivateCommand extends Command
{
    /**
     * Flip bank_accounts.active from true to false for a single account.
     *
     * Success: sets active=false for exactly one row via a conditional UPDATE
     *   (WHERE id=? AND active=true) inside a DB transaction. Prints
     *   accounts_deactivated=1 and exits 0.
     *
     * Failure modes:
     *   - --id not provided without a TTY → FAILURE (error message emitted).
     *   - --id is not a valid UUID → FAILURE ("--id is not a valid UUID: {id}").
     *   - No bank_account found for the given id → FAILURE.
     *   - Account is already inactive → FAILURE ("bank_account {id} is already inactive.").
     *   - Account has unpushed rows and --force not passed → FAILURE with count.
     *   - Operator declines the confirmation prompt → FAILURE ("Aborted.").
     *   - Concurrent deactivation wins the UPDATE race → FAILURE (rowcount=0).
     *
     * Side effects: single UPDATE bank_accounts SET active=false WHERE id=? AND active=true
     *   wrapped in DB::transaction. Tables NOT touched:
     *   bank_account_sessions, bank_account_sync_state, bank_account_identifiers, transactions.
     *
     * Downstream invariants (active=false):
     *   - PushRunner excludes the account's rows from its candidate set — no outbound YNAB writes.
     *   - StatusSnapshotBuilder::loadQueuedCounts excludes the account — queued counts drop to zero.
     *   - StatusSnapshotBuilder::loadStuckTransactions excludes the account — stuck panel empty for it.
     *   Re-activating (UPDATE bank_accounts SET active=true WHERE id=?) restores all three.
     *
     * --force semantics: bypasses the unpushed-row guard. Those rows become dead — not pushable,
     *   not visible in spendula:status — until the account is reactivated. Use only when the bank
     *   has already closed the account and the unpushed rows are orphans by definition.
     *
     * Idempotency: refused on already-inactive — operator-typo guard, not silent no-op.
     *
     * Concurrency: no advisory lock (same carve-out as accounts:map — single-row UPDATE-LAST-WINS).
     *   The WHERE active=true predicate in the UPDATE is the authoritative race guard: if two
     *   callers reach the UPDATE concurrently, exactly one gets affected=1 and the other gets
     *   affected=0 (and surfaces an error). The pre-check (step 5) is best-effort.
     *   Race against an in-flight spendula:push: PushRunner holds AdvisoryLock::PUSH while
     *   loading its candidate set; if push has already snapshotted its batch before this commit,
     *   that one run may still POST the row. Subsequent runs honour the invariant.
     *
     * Reactivation: UPDATE bank_accounts SET active=true WHERE id=?  (one-line SQL today).
     *   Consider adding spendula:accounts:reactivate when a second concrete need appears.
     */
    public function handle(): int
    {
        $id = is_string($this->option('id')) ? trim((string) $this->option('id')) : '';
        $force = (bool) $this->option('force');

        if ($id === '') {
            // Non-interactive path requires --id. The interactive picker
            // can't function without a TTY for the same reason as accounts:map:
            // Symfony returns each prompt's default, so --no-interaction /
            // backgrounded callers would silently cancel rather than surface the error.
            $hasTty = ! defined('STDIN')
                ? true
                : (bool) @stream_isatty(STDIN);
            $effectivelyNonInteractive =
                (bool) $this->option('no-interaction')
                || $this->input->isInteractive() === false
                || (! app()->runningUnitTests() && ! $hasTty);

            if ($effectivelyNonInteractive) {
                $this->error('No TTY available. Pass --id=<uuid> for non-interactive use.');

                return self::FAILURE;
            }

            return $this->runInteractive($force);
        }

        // UUID validation before any DB query — prevents a Postgres "invalid
        // input syntax for type uuid" error from surfacing as an unhandled
        // QueryException.
        if (! Str::isUuid($id)) {
            $this->error("--id is not a valid UUID: {$id}.");

            return self::FAILURE;
        }

        return $this->runScripted($id, $force);
    }

    private function runInteractive(bool $force): int
    {
        $candidates = BankAccount::query()
            ->where('active', true)
            ->orderBy('bank_slug')
            ->orderBy('currency')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No active accounts.');

            return self::SUCCESS;
        }

        // Full UUID in the label — 8-char prefixes can collide for UUIDv7 rows
        // created in the same millisecond; full UUID is the only collision-proof key.
        $cancelLabel = '[cancel]';
        $labelToId = [];
        foreach ($candidates as $account) {
            $ibanPart = $account->iban ?? '(no IBAN)';
            $label = sprintf(
                '%s %s %s [%s]',
                $account->bank_slug,
                $account->currency,
                $ibanPart,
                $account->id,
            );
            $labelToId[$label] = $account->id;
        }

        $choices = array_merge(array_keys($labelToId), [$cancelLabel]);
        $pickedRaw = $this->choice('Pick an account to deactivate', $choices, $cancelLabel);
        $picked = is_string($pickedRaw) ? $pickedRaw : $cancelLabel;

        if ($picked === $cancelLabel || ! isset($labelToId[$picked])) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        return $this->runScripted($labelToId[$picked], $force);
    }

    private function runScripted(string $id, bool $force): int
    {
        $account = BankAccount::query()->find($id);
        if (! $account instanceof BankAccount) {
            $this->error("No bank_account with id={$id}.");

            return self::FAILURE;
        }

        if ($account->active === false) {
            $this->error("bank_account {$id} is already inactive.");

            return self::FAILURE;
        }

        $unpushed = Transaction::query()
            ->where('bank_account_id', $account->id)
            ->whereIn('status', [TransactionStatus::Approved->value, TransactionStatus::Transfer->value])
            ->count();

        $total = Transaction::query()
            ->where('bank_account_id', $account->id)
            ->count();

        $this->table(
            ['Field', 'Value'],
            [
                ['id', $account->id],
                ['bank_slug', $account->bank_slug],
                ['display_name', $account->display_name ?? '(none)'],
                ['iban', $account->iban ?? '(none)'],
                ['currency', $account->currency],
                ['ynab_account_id', $account->ynab_account_id ?? '(none)'],
                ['transactions_total', $total],
                ['transactions_unpushed', $unpushed],
            ],
        );

        if ($unpushed > 0 && ! $force) {
            $this->error(
                "{$unpushed} transaction(s) in approved/transfer status — push or skip them first, "
                .'or pass --force to deactivate anyway (those rows will become dead: not pushable, '
                .'not visible in spendula:status).'
            );

            return self::FAILURE;
        }

        $confirmQuestion = $unpushed > 0 && $force
            ? "Deactivate this account? {$unpushed} approved/transfer transaction(s) will become DEAD: "
              .'not pushable, not visible in spendula:status, until the account is reactivated. (y/N)'
            : 'Deactivate this account? Sync will stop attempting it. (y/N)';

        if (! $this->confirm($confirmQuestion, false)) {
            $this->info('Aborted.');

            return self::FAILURE;
        }

        $affected = 0;
        DB::transaction(function () use ($account, &$affected): void {
            $affected = BankAccount::query()
                ->whereKey($account->id)
                ->where('active', true)
                ->update(['active' => false]);

            if ($affected === 0) {
                throw new \RuntimeException(
                    "bank_account {$account->id} was already deactivated by a concurrent call."
                );
            }
        });

        $this->line('accounts_deactivated=1');

        return self::SUCCESS;
    }
}
