<?php

namespace App\Services\Review;

use App\Enums\TransactionStatus;
use App\Models\PayeeRule;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * GH #39 — read-side of the auto-decision pipeline. Given the queue of
 * `fetched` transactions, looks up matching `payee_rules` and routes
 * each match through `TransactionActions` so the existing audit trail
 * (skipped_at, skip_reason, status) is identical to a manually-decided
 * transaction.
 *
 * Match key: `(bank_slug, counterparty_name)`, exact case-sensitive
 * string equality. The L0/L1 `name_rules` pipeline (#33) canonicalises
 * counterparty names before they reach this engine, so case drift is
 * rare; if a future bank file leaks an inconsistent casing, the operator
 * cleans up via `spendula:rules:delete` rather than the engine
 * silently merging different keys.
 */
class PayeeRuleEngine
{
    public function __construct(private readonly TransactionActions $actions) {}

    /**
     * Apply matching rules to every transaction in `$queue` whose
     * (bank_slug, counterparty_name) pair has a `payee_rules` row.
     * Mutates each matched transaction in place via `TransactionActions`.
     *
     * Success: returns an associative array describing the auto-applied
     *   set:
     *   - `appliedIds`: list of Transaction::id values that were
     *     auto-applied, in queue order (so the caller can replay them
     *     in the override sub-loop).
     *   - `byAction`: counts keyed by 'approved' / 'skipped' /
     *     'transferred' (the same shape the interactive loop uses for
     *     stats reporting).
     *
     * Side effects: one UPDATE per matching transaction (via
     *   `TransactionActions`). Reads `payee_rules` once. Idempotent —
     *   running it twice over the same queue results in 0 additional
     *   matches because all matched rows are no longer at status
     *   `fetched`.
     *
     * Concurrency: must be invoked under the REVIEW advisory lock —
     *   same scope as the interactive loop. ReviewCommand is the only
     *   caller and already holds the lock.
     *
     * @param  EloquentCollection<int, Transaction>  $queue
     * @return array{appliedIds: list<string>, byAction: array{approved: int, skipped: int, transferred: int}}
     */
    public function applyRules(EloquentCollection $queue): array
    {
        $byAction = ['approved' => 0, 'skipped' => 0, 'transferred' => 0];
        /** @var list<string> $appliedIds */
        $appliedIds = [];

        if ($queue->isEmpty()) {
            return ['appliedIds' => $appliedIds, 'byAction' => $byAction];
        }

        $rules = $this->loadRules($queue);
        if ($rules->isEmpty()) {
            return ['appliedIds' => $appliedIds, 'byAction' => $byAction];
        }

        foreach ($queue as $transaction) {
            $key = $this->keyFor($transaction);
            if ($key === null) {
                continue;
            }

            $rule = $rules->get($key);
            if ($rule === null) {
                continue;
            }

            $statKey = $this->applyOne($transaction, $rule);
            $byAction[$statKey]++;
            $appliedIds[] = $transaction->id;
        }

        return ['appliedIds' => $appliedIds, 'byAction' => $byAction];
    }

    /**
     * @return 'approved'|'skipped'|'transferred'
     */
    private function applyOne(Transaction $transaction, PayeeRule $rule): string
    {
        return match ($rule->action) {
            TransactionStatus::Approved => $this->approveAndReturnKey($transaction),
            TransactionStatus::Skipped => $this->skipAndReturnKey($transaction, $rule->skip_reason),
            TransactionStatus::Transfer => $this->transferAndReturnKey($transaction),
            default => 'approved', // CHECK constraint forbids any other value at the DB layer.
        };
    }

    /**
     * @return 'approved'
     */
    private function approveAndReturnKey(Transaction $transaction): string
    {
        $this->actions->approve($transaction);

        return 'approved';
    }

    /**
     * @return 'skipped'
     */
    private function skipAndReturnKey(Transaction $transaction, ?string $reason): string
    {
        $this->actions->skip($transaction, $reason);

        return 'skipped';
    }

    /**
     * @return 'transferred'
     */
    private function transferAndReturnKey(Transaction $transaction): string
    {
        $this->actions->markTransfer($transaction);

        return 'transferred';
    }

    /**
     * Load every `payee_rules` row that could match anything in `$queue`,
     * keyed by "{bank_slug}\0{counterparty_name}" for O(1) lookup. The
     * NUL byte separator avoids ambiguity if a counterparty name ever
     * contains a literal "{slug}|" prefix.
     *
     * @param  EloquentCollection<int, Transaction>  $queue
     * @return Collection<string, PayeeRule>
     */
    private function loadRules(EloquentCollection $queue): Collection
    {
        /** @var array<string, list<string>> $namesByBank */
        $namesByBank = [];
        foreach ($queue as $transaction) {
            $bankSlug = $transaction->bankAccount?->bank_slug;
            $name = $transaction->counterparty_name;
            if ($bankSlug === null || $name === null || $name === '') {
                continue;
            }
            $namesByBank[$bankSlug][] = $name;
        }

        if ($namesByBank === []) {
            return new Collection;
        }

        $query = PayeeRule::query();
        $query->where(function ($outer) use ($namesByBank): void {
            foreach ($namesByBank as $bankSlug => $names) {
                $outer->orWhere(function ($inner) use ($bankSlug, $names): void {
                    $inner->where('bank_slug', $bankSlug)
                        ->whereIn('counterparty_name', array_values(array_unique($names)));
                });
            }
        });

        /** @var EloquentCollection<int, PayeeRule> $rows */
        $rows = $query->get();

        return $rows->keyBy(fn (PayeeRule $rule): string => $rule->bank_slug."\0".$rule->counterparty_name);
    }

    private function keyFor(Transaction $transaction): ?string
    {
        $bankSlug = $transaction->bankAccount?->bank_slug;
        $name = $transaction->counterparty_name;

        if ($bankSlug === null || $name === null || $name === '') {
            return null;
        }

        return $bankSlug."\0".$name;
    }
}
