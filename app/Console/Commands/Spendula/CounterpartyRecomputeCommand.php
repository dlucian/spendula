<?php

namespace App\Console\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Counterparty\OwnAccountClassifier;
use App\Services\Counterparty\Resolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spendula:counterparty:recompute
    {--bank= : Optional bank slug to scope the recompute to.}
    {--dry-run : Print before/after distribution without writing.}
')]
#[Description('Re-resolve counterparty_name and counterparty_resolution_level from raw_payload. Use after tuning the Resolver.')]
class CounterpartyRecomputeCommand extends Command
{
    /**
     * Walk every transaction, run the (current) Resolver against its
     * stored raw_payload, and update `counterparty_name` /
     * `counterparty_resolution_level` if either changed.
     *
     * Does NOT touch `dedup_hash`. That field is derived from the *raw*
     * pre-resolution counterparty (creditor.name / debtor.name pulled
     * from raw_payload by MatchUpdateOrInsert::extractRawCounterparty),
     * not from `counterparty_name` or the L2 remittance extraction this
     * command re-runs. Tuning the Resolver therefore has no effect on
     * dedup_hash for existing rows — leaving it alone keeps the
     * (bank_account_id, dedup_hash, occurrence) unique stable.
     *
     * Side effects: per-row UPDATE on the `transactions` table. No
     * advisory lock — concurrent syncs stomp on the same rows but this
     * command only runs manually after a Resolver tuning, so the race
     * window is operator-controlled.
     *
     * Own-account promotion: after re-resolving the counterparty name, the
     * OwnAccountClassifier is applied. Both same-currency and cross-currency
     * (FX) own-account rows in status=fetched are promoted to transfer.
     * Rows in any other status (approved, skipped, pushed, tracking, transfer)
     * are never status-mutated here — SPEC §5.3 no-regress. Already-pushed
     * own-account rows must be corrected manually in YNAB; YNAB deduplicates
     * on import_id so the historical push stands as-is.
     */
    public function handle(Resolver $resolver, OwnAccountClassifier $classifier): int
    {
        $bankSlug = (string) $this->option('bank');
        $dryRun = (bool) $this->option('dry-run');

        $query = Transaction::query()->with('bankAccount')->orderBy('id');
        if ($bankSlug !== '') {
            $bankAccountIds = BankAccount::query()->where('bank_slug', $bankSlug)->pluck('id');
            if ($bankAccountIds->isEmpty()) {
                $this->error("No bank_accounts for slug '{$bankSlug}'.");

                return self::FAILURE;
            }
            $query->whereIn('bank_account_id', $bankAccountIds);
        }

        $beforeLevels = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $afterLevels = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $scanned = 0;
        $levelChanged = 0;
        $nameChanged = 0;

        $query->chunkById(200, function ($chunk) use ($resolver, $classifier, $dryRun, &$scanned, &$levelChanged, &$nameChanged, &$beforeLevels, &$afterLevels) {
            /** @var iterable<Transaction> $chunk */
            foreach ($chunk as $tx) {
                $scanned++;
                $beforeLevels[$tx->counterparty_resolution_level] = ($beforeLevels[$tx->counterparty_resolution_level] ?? 0) + 1;

                $txBankSlug = $tx->bankAccount?->bank_slug;
                $resolved = $resolver->resolve($tx->raw_payload, $txBankSlug);

                // Apply own-account name override (same logic as MatchUpdateOrInsert).
                $newName = $resolved->name;
                $ownAccountTransfer = false;

                $source = $tx->bankAccount;
                if ($source !== null) {
                    $classification = $classifier->classify($tx->raw_payload, $source);
                    if ($classification !== null) {
                        // Both same-currency and cross-currency (FX) own-account moves
                        // resolve to a transfer (see DECISIONS.md — GH #14 FX-as-transfer
                        // reversal). The sameCurrency flag no longer drives a name split;
                        // all own-account classifications get the Transfer prefix.
                        $prefix = (string) config('spendula.own_account.transfer_prefix', 'Transfer');
                        $newName = mb_substr("{$prefix} : {$classification->destinationLabel()}", 0, 64);
                        $ownAccountTransfer = true;
                    }
                }

                $afterLevels[$resolved->level] = ($afterLevels[$resolved->level] ?? 0) + 1;

                $levelDiff = $tx->counterparty_resolution_level !== $resolved->level;
                $nameDiff = ($tx->counterparty_name ?? '') !== $newName;

                // Promote fetched→transfer for own-account rows (both same-currency and FX).
                // Never mutate status beyond fetched (approved/skipped/pushed/tracking/transfer
                // are operator-reviewed or terminal states — SPEC §5.3 no-regress).
                $statusPromotion = $ownAccountTransfer && $tx->status === TransactionStatus::Fetched;

                if ($levelDiff) {
                    $levelChanged++;
                }
                if ($nameDiff) {
                    $nameChanged++;
                }

                if (! $dryRun && ($levelDiff || $nameDiff || $statusPromotion)) {
                    $tx->counterparty_name = $newName;
                    $tx->counterparty_resolution_level = $resolved->level;
                    if ($statusPromotion) {
                        $tx->status = TransactionStatus::Transfer;
                    }
                    $tx->save();
                }
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Scanned %d transaction(s)%s. level_changed=%d name_changed=%d.',
            $scanned,
            $bankSlug !== '' ? " for bank='{$bankSlug}'" : '',
            $levelChanged,
            $nameChanged,
        ));

        $this->newLine();
        $this->line('Resolution level distribution:');
        $this->line(sprintf('  %-7s %8s %8s', 'level', 'before', 'after'));
        foreach ([0, 1, 2, 3, 4] as $lvl) {
            $this->line(sprintf('  L%d      %8d %8d', $lvl, $beforeLevels[$lvl] ?? 0, $afterLevels[$lvl] ?? 0));
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('--dry-run set: no rows written.');
        }

        return self::SUCCESS;
    }
}
