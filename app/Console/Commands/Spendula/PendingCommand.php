<?php

namespace App\Console\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Money\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('spendula:pending
    {--json : Emit a JSON document instead of the human-readable table.}
    {--bank= : Filter to a single bank_slug (e.g. revolutlt).}
    {--limit= : Cap the number of rows returned. Default: unlimited.}
')]
#[Description('List fetched transactions awaiting review (read-only; supports --json for agent ingestion).')]
class PendingCommand extends Command
{
    /**
     * Emit the current `status=fetched` queue as either a Symfony table (default)
     * or a single JSON document (--json).
     *
     * Success: returns SUCCESS after writing one JSON document or one table to
     *   stdout. The set of rows reflects `status=fetched` at the moment of the
     *   query; the command does not retry or refresh. Empty queue: JSON emits
     *   `{"count":0,"transactions":[]}`, table prints "Nothing pending." to the
     *   output stream.
     *
     * Failure: returns FAILURE only on invalid --limit input. No exceptions are
     *   caught — DB connection errors propagate.
     *
     * Side effects: none. No DB writes, no HTTP calls, no advisory lock, no
     *   file I/O.
     *
     * Idempotency: trivially idempotent (read-only).
     *
     * Concurrency: safe alongside any other spendula:* command. Can race with
     *   spendula:review flipping rows out of `fetched` mid-snapshot; the caller
     *   treats the response as a point-in-time snapshot, not authoritative.
     */
    public function handle(): int
    {
        $limitRaw = $this->option('limit');
        $limit = null;

        if ($limitRaw !== null) {
            if (! ctype_digit((string) $limitRaw) || (int) $limitRaw < 1) {
                $this->error('--limit must be a positive integer.');

                return self::FAILURE;
            }
            $limit = (int) $limitRaw;
        }

        $bankSlug = (string) $this->option('bank');

        $query = Transaction::query()
            ->where('status', TransactionStatus::Fetched->value)
            ->with('bankAccount.bank')
            ->orderBy('bank_account_id')
            ->orderBy('booking_date')
            ->orderBy('occurrence')
            ->orderBy('id');

        if ($bankSlug !== '') {
            $accountIds = BankAccount::query()
                ->where('bank_slug', $bankSlug)
                ->pluck('id');

            $query->whereIn('bank_account_id', $accountIds);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $transactions = $query->get();

        if ($this->option('json')) {
            return $this->outputJson($transactions);
        }

        return $this->outputTable($transactions);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function outputJson(Collection $transactions): int
    {
        $rows = $transactions->map(fn (Transaction $tx) => $this->toArray($tx))->values()->all();

        $doc = json_encode(
            ['count' => count($rows), 'transactions' => $rows],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $this->getOutput()->writeln($doc, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function outputTable(Collection $transactions): int
    {
        if ($transactions->isEmpty()) {
            $this->info('Nothing pending.');

            return self::SUCCESS;
        }

        $rows = $transactions->map(function (Transaction $tx): array {
            $account = $tx->bankAccount;
            $bankSlug = $account !== null ? $account->bank_slug : '';
            $accountLabel = $account !== null
                ? ($account->display_name ?? $account->iban ?? (string) $account->id)
                : (string) $tx->bank_account_id;
            $amount = Money::format(abs($tx->amount_milliunits), $tx->currency);

            return [
                $tx->id,
                $bankSlug.' / '.$accountLabel,
                $tx->booking_date->toDateString(),
                $amount,
                $tx->currency,
                $tx->counterparty_name ?? '',
                (string) $tx->counterparty_resolution_level,
            ];
        })->values()->all();

        $this->table(
            ['id', 'account', 'date', 'amount', 'currency', 'counterparty', 'level'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Transaction $tx): array
    {
        $account = $tx->bankAccount;
        $bankSlug = $account !== null ? $account->bank_slug : '';
        $bankAccountLabel = $account !== null
            ? ($account->display_name ?? $account->iban ?? (string) $account->id)
            : $tx->bank_account_id;

        $currency = $tx->currency;
        $absMilliunits = abs($tx->amount_milliunits);
        $amount = bcdiv((string) $absMilliunits, '1000', Money::decimalPlaces($currency));

        return [
            'id' => $tx->id,
            'bank_slug' => $bankSlug,
            'bank_account_id' => $tx->bank_account_id,
            'bank_account_label' => $bankAccountLabel,
            'currency' => $currency,
            'booking_date' => $tx->booking_date->toDateString(),
            'amount' => $amount,
            'amount_milliunits' => $tx->amount_milliunits,
            'counterparty_name' => $tx->counterparty_name,
            'counterparty_resolution_level' => $tx->counterparty_resolution_level,
            'remittance_information' => $tx->remittance_information,
            'entry_ref' => $tx->entry_reference,
            'occurrence' => $tx->occurrence,
        ];
    }
}
