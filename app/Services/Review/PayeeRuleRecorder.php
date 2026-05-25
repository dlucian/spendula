<?php

namespace App\Services\Review;

use App\Enums\TransactionStatus;
use App\Models\PayeeRule;
use App\Models\Transaction;

/**
 * GH #39 — record-side of the auto-decision pipeline. Owns rule
 * creation, update, and delete; never touches the transaction itself.
 *
 * The split with PayeeRuleEngine is deliberate: the engine reads rules
 * to drive the bulk auto-apply pass, while the recorder writes rules in
 * response to interactive operator decisions. Guard logic lives here so
 * a future caller (e.g. a hypothetical web UI) can't bypass it.
 */
class PayeeRuleRecorder
{
    /**
     * Create a rule for the (bank_slug, counterparty_name) pair derived
     * from `$transaction`, capturing `$action` (and `$skipReason` when
     * `$action === Skipped`).
     *
     * Success: `RecordResult::Created` when a fresh row is inserted.
     *   `RecordResult::AlreadyExists` when a rule already covers the
     *   pair — caller should treat the existing rule as authoritative
     *   and not overwrite it from a one-off interactive decision.
     *   `RecordResult::SkippedByGuard` when one of the guards declined
     *   to record (resolution level ≥ 4, blank counterparty, or name on
     *   the bank-internal / operator-name denylist).
     *
     * Side effects: at most one INSERT into `payee_rules`. No transaction
     *   state is mutated; the caller has already (or will) call
     *   `TransactionActions::*` on the transaction itself.
     *
     * Idempotency: safe to call repeatedly — the unique
     *   (bank_slug, counterparty_name) index plus the
     *   firstOrCreate-shaped logic make a second call return
     *   AlreadyExists rather than throwing.
     */
    public function record(
        Transaction $transaction,
        TransactionStatus $action,
        ?string $skipReason = null,
    ): RecordResult {
        $bankSlug = $this->bankSlugFor($transaction);
        $name = $transaction->counterparty_name;

        if ($bankSlug === null || $name === null || trim($name) === '') {
            return RecordResult::SkippedByGuard;
        }

        if ($transaction->counterparty_resolution_level >= 4) {
            return RecordResult::SkippedByGuard;
        }

        if ($this->isOnDenylist($name)) {
            return RecordResult::SkippedByGuard;
        }

        // findOrCreate-shaped: race-safe under the REVIEW advisory lock,
        // but the unique index is the real guarantee.
        $existing = PayeeRule::query()
            ->where('bank_slug', $bankSlug)
            ->where('counterparty_name', $name)
            ->first();

        if ($existing !== null) {
            return RecordResult::AlreadyExists;
        }

        PayeeRule::query()->create([
            'bank_slug' => $bankSlug,
            'counterparty_name' => $name,
            'action' => $action->value,
            'skip_reason' => $action === TransactionStatus::Skipped && $skipReason !== null && trim($skipReason) !== ''
                ? trim($skipReason)
                : null,
        ]);

        return RecordResult::Created;
    }

    /**
     * Look up the rule (if any) that would auto-apply to `$transaction`.
     * Returns null when the transaction has no bank or no payee, or no
     * matching rule exists.
     */
    public function findFor(Transaction $transaction): ?PayeeRule
    {
        $bankSlug = $this->bankSlugFor($transaction);
        $name = $transaction->counterparty_name;

        if ($bankSlug === null || $name === null) {
            return null;
        }

        return PayeeRule::query()
            ->where('bank_slug', $bankSlug)
            ->where('counterparty_name', $name)
            ->first();
    }

    /**
     * Mutate `$rule` in place to reflect a new action (and optional skip
     * reason). Saves and returns the refreshed model. The CHECK constraint
     * (skip_reason only when action='skipped') is enforced at the DB layer;
     * we additionally clear skip_reason on non-skip transitions so the
     * pre-save state doesn't violate the invariant.
     */
    public function update(
        PayeeRule $rule,
        TransactionStatus $action,
        ?string $skipReason = null,
    ): PayeeRule {
        $rule->action = $action;
        $rule->skip_reason = $action === TransactionStatus::Skipped && $skipReason !== null && trim($skipReason) !== ''
            ? trim($skipReason)
            : null;
        $rule->save();

        return $rule;
    }

    /**
     * Insert (or, with `$force`, overwrite) a rule for the given
     * `(bank_slug, counterparty_name)` pair — the agent/operator-facing
     * entry point for out-of-band rule installs (GH #8).
     *
     * Success:
     *   `RecordResult::Created` when no rule existed and a fresh row was
     *   inserted. `RecordResult::Updated` when a rule already existed and
     *   `$force === true` — the existing row is mutated in place via
     *   `update()`, which also clears `skip_reason` on non-skip
     *   transitions to honour the DB CHECK constraint.
     *   `RecordResult::AlreadyExists` when a rule exists and
     *   `$force === false` — nothing is written; the caller decides
     *   whether to surface the existing row's id to the operator.
     *   `RecordResult::SkippedByGuard` when `isOnDenylist()` matches —
     *   nothing is written regardless of `$force`.
     *
     * Failure: no exceptions thrown for expected states (guard trip,
     *   duplicate, etc.) — all are captured in the return enum. DB
     *   exceptions (constraint violation, connection error) propagate
     *   as-is; callers should let them bubble.
     *
     * Side effects: at most one INSERT or UPDATE against `payee_rules`.
     *   No HTTP, no queue, no advisory lock. Thread-safe at the DB level
     *   via the unique index on `(bank_slug, counterparty_name)`.
     *
     * Idempotency: with `$force = true`, calling with the same inputs
     *   converges to the same final DB state. With `$force = false`, a
     *   second call for an existing pair returns `AlreadyExists`.
     *
     * Concurrency: no advisory lock (matches rules:list / rules:delete).
     *   Two simultaneous inserts for the same pair will race; the second
     *   surfaces a unique-constraint exception. Acceptable for the
     *   operator/agent single-threaded use case.
     *
     * Note: does NOT apply the `counterparty_resolution_level` guard —
     *   there is no transaction in scope; explicit operator install trusts
     *   the caller's judgement.
     */
    public function recordDirect(
        string $bankSlug,
        string $counterpartyName,
        TransactionStatus $action,
        ?string $skipReason = null,
        bool $force = false,
    ): RecordResult {
        if ($this->isOnDenylist($counterpartyName)) {
            return RecordResult::SkippedByGuard;
        }

        $existing = PayeeRule::query()
            ->where('bank_slug', $bankSlug)
            ->where('counterparty_name', $counterpartyName)
            ->first();

        if ($existing !== null) {
            if (! $force) {
                return RecordResult::AlreadyExists;
            }
            $this->update($existing, $action, $skipReason);

            return RecordResult::Updated;
        }

        PayeeRule::query()->create([
            'bank_slug' => $bankSlug,
            'counterparty_name' => $counterpartyName,
            'action' => $action->value,
            'skip_reason' => $action === TransactionStatus::Skipped && $skipReason !== null && trim($skipReason) !== ''
                ? trim($skipReason)
                : null,
        ]);

        return RecordResult::Created;
    }

    public function delete(PayeeRule $rule): void
    {
        $rule->delete();
    }

    private function bankSlugFor(Transaction $transaction): ?string
    {
        $account = $transaction->bankAccount;

        return $account?->bank_slug;
    }

    public function isOnDenylist(string $name): bool
    {
        $needle = mb_strtolower(trim($name));

        /** @var list<string> $internal */
        $internal = (array) config('spendula.payee_rule_guards.bank_internal_payees', []);
        /** @var list<string> $operators */
        $operators = (array) config('spendula.payee_rule_guards.operator_names', []);

        foreach ([...$internal, ...$operators] as $entry) {
            if (mb_strtolower(trim($entry)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
