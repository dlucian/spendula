<?php

namespace App\Console\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\PayeeRule;
use App\Services\Review\PayeeRuleRecorder;
use App\Services\Review\RecordResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:rules:add
    {bank_slug : Bank slug the rule applies to.}
    {counterparty_name : Exact counterparty name (case-sensitive, must match what sync writes).}
    {action : approve | skip | transfer.}
    {--reason= : Skip reason. Only valid with action=skip.}
    {--force : Overwrite an existing rule for the same (bank_slug, counterparty_name).}
')]
#[Description('Insert (or, with --force, overwrite) a payee_rules row directly without going through the interactive review session (GH #8).')]
class RulesAddCommand extends Command
{
    public function __construct(private readonly PayeeRuleRecorder $recorder)
    {
        parent::__construct();
    }

    /**
     * Insert or overwrite a `payee_rules` row for an explicit
     * `(bank_slug, counterparty_name, action)` triple. Designed for the
     * financial-expert agent and operator use cases outside the
     * interactive review session.
     *
     * Success contract: `RecordResult::Created` (fresh insert) and
     *   `RecordResult::Updated` (force overwrite) both print
     *   `Rule added: <id> <bank_slug> <counterparty_name> <action>` and
     *   exit 0. The stable single-line format keeps script consumers
     *   simple — no separate "Rule updated:" variant.
     *
     * Failure modes: exits non-zero with a `$this->error()` line when:
     *   - `action` is not one of approve/skip/transfer (bad mapping, no DB hit).
     *   - `--reason` is set but `action !== skip` (DB CHECK would catch it,
     *     but this gives a cleaner per-arg message).
     *   - `bank_slug` is not found in `banks` (FK would also catch it).
     *   - `counterparty_name` is blank after trim.
     *   - `RecordResult::AlreadyExists` — includes the existing rule id so
     *     the operator can copy it into `spendula:rules:delete <id>`.
     *   - `RecordResult::SkippedByGuard` — name is on the denylist.
     *
     * Side effects: at most one INSERT or UPDATE against `payee_rules`.
     *   No HTTP calls, no queue, no advisory lock (matches rules:list /
     *   rules:delete; `payee_rules` is operator metadata).
     *
     * Idempotency: with `--force`, repeated calls with the same inputs
     *   produce the same final DB state. Without `--force`, a second call
     *   for an existing `(bank_slug, counterparty_name)` exits non-zero.
     *
     * Concurrency: no advisory lock. The DB unique index on
     *   `(bank_slug, counterparty_name)` serialises concurrent inserts.
     */
    public function handle(): int
    {
        $bankSlug = (string) $this->argument('bank_slug');
        $counterpartyName = (string) $this->argument('counterparty_name');
        $actionInput = (string) $this->argument('action');
        /** @var string|null $reason */
        $reason = $this->option('reason');
        $force = (bool) $this->option('force');

        $action = match ($actionInput) {
            'approve' => TransactionStatus::Approved,
            'skip' => TransactionStatus::Skipped,
            'transfer' => TransactionStatus::Transfer,
            default => null,
        };

        if ($action === null) {
            $this->error("Invalid action '{$actionInput}'. Allowed: approve, skip, transfer.");

            return self::FAILURE;
        }

        if ($reason !== null && $action !== TransactionStatus::Skipped) {
            $this->error('--reason is only valid when action is skip.');

            return self::FAILURE;
        }

        if (Bank::query()->whereKey($bankSlug)->doesntExist()) {
            $this->error("Bank '{$bankSlug}' not found.");

            return self::FAILURE;
        }

        if (trim($counterpartyName) === '') {
            $this->error('counterparty_name must not be blank.');

            return self::FAILURE;
        }

        $result = $this->recorder->recordDirect($bankSlug, $counterpartyName, $action, $reason, $force);

        if ($result === RecordResult::SkippedByGuard) {
            $this->error("Counterparty '{$counterpartyName}' is on the bank-internal/operator denylist; rule not added.");

            return self::FAILURE;
        }

        if ($result === RecordResult::AlreadyExists) {
            /** @var PayeeRule $existing */
            $existing = PayeeRule::query()
                ->where('bank_slug', $bankSlug)
                ->where('counterparty_name', $counterpartyName)
                ->firstOrFail();
            $this->error("Rule exists for {$bankSlug} + {$counterpartyName} (id {$existing->id}), use --force or 'spendula:rules:delete {$existing->id}' first.");

            return self::FAILURE;
        }

        // Created or Updated
        /** @var PayeeRule $rule */
        $rule = PayeeRule::query()
            ->where('bank_slug', $bankSlug)
            ->where('counterparty_name', $counterpartyName)
            ->firstOrFail();
        $this->info("Rule added: {$rule->id} {$rule->bank_slug} {$rule->counterparty_name} {$rule->action->value}");

        return self::SUCCESS;
    }
}
