<?php

namespace App\Services\Push;

use App\Enums\PushErrorType;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\BankAccount;
use App\Models\PushRun;
use App\Models\PushRunError;
use App\Models\Transaction;
use App\Services\Locks\AdvisoryLock;
use App\Services\Ynab\Client;
use App\Services\Ynab\Exceptions\YnabAuthException;
use App\Services\Ynab\Exceptions\YnabException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * spendula:push implementation. SPEC §7.2–7.5.
 *
 * Groups approved/transfer rows by bank_account_id, builds YNAB bulk
 * payloads, POSTs them. Processes both `data.transactions` (created)
 * and `data.duplicate_import_ids` (server-side dedup hit) paths —
 * both transition the local row to status=pushed. Transactions that
 * appear in neither list stay approved and get push_attempt_count++.
 *
 * Retry gating (§7.2 step 3): skip rows whose last_push_attempt_at is
 * within the last 10 minutes. Prevents thundering-herd retries when
 * the operator runs push in a loop while chasing a bug.
 */
class PushRunner
{
    private const int RETRY_GATE_MINUTES = 10;

    public function __construct(
        private readonly Client $client,
        private readonly PayloadBuilder $payloadBuilder,
    ) {}

    public function run(): PushResult
    {
        /** @var PushResult $result */
        $result = AdvisoryLock::withLock(AdvisoryLock::PUSH, fn (): PushResult => $this->runLocked());

        return $result;
    }

    private function runLocked(): PushResult
    {
        $pushRun = PushRun::query()->create(['started_at' => Carbon::now()]);
        $counters = ['pushed' => 0, 'duplicate' => 0, 'errors' => 0];

        $retryGate = Carbon::now()->subMinutes(self::RETRY_GATE_MINUTES);

        /** @var array<string, list<Transaction>> $grouped keyed by bank_account_id */
        $grouped = [];

        Transaction::query()
            ->whereIn('status', [TransactionStatus::Approved->value, TransactionStatus::Transfer->value])
            ->whereNull('ynab_transaction_id')
            ->where(function ($q) use ($retryGate) {
                $q->whereNull('last_push_attempt_at')->orWhere('last_push_attempt_at', '<', $retryGate);
            })
            ->whereIn('bank_account_id', BankAccount::query()
                ->where('ynab_account_type', YnabAccountType::OnBudget->value)
                ->whereNotNull('ynab_account_id')
                ->select('id'))
            ->orderBy('bank_account_id')
            ->orderBy('booking_date')
            ->chunk(100, function ($rows) use (&$grouped): void {
                foreach ($rows as $row) {
                    $grouped[$row->bank_account_id][] = $row;
                }
            });

        foreach ($grouped as $bankAccountId => $transactions) {
            $account = BankAccount::query()->find($bankAccountId);
            if (! $account instanceof BankAccount) {
                continue;
            }

            try {
                $this->pushGroup($account, $transactions, $pushRun, $counters);
            } catch (YnabAuthException $e) {
                $this->logError($pushRun, null, PushErrorType::Auth, $e);
                $counters['errors']++;
                $this->finaliseRun($pushRun, $counters);
                throw $e;
            }
        }

        $this->finaliseRun($pushRun, $counters);

        return new PushResult($pushRun->refresh(), $counters['pushed'], $counters['duplicate'], $counters['errors']);
    }

    /**
     * @param  list<Transaction>  $transactions
     * @param  array{pushed:int,duplicate:int,errors:int}  $counters
     */
    private function pushGroup(BankAccount $account, array $transactions, PushRun $pushRun, array &$counters): void
    {
        $payloads = [];
        /** @var array<string, Transaction> $byImportId */
        $byImportId = [];

        foreach ($transactions as $transaction) {
            $payload = $this->payloadBuilder->build($transaction, $account);

            // Pin the import_id to the local row before talking to YNAB. If the
            // request succeeds server-side but our retry happens after a later
            // sync mutated raw_payload/counterparty, the next PayloadBuilder::build()
            // would otherwise hash a different import_id and the duplicate_import_ids
            // path could no longer protect us from creating a second transaction.
            if ($transaction->ynab_import_id === null) {
                $transaction->ynab_import_id = (string) $payload['import_id'];
                $transaction->save();
            }

            $payloads[] = $payload;
            $byImportId[(string) $payload['import_id']] = $transaction;
        }

        $now = Carbon::now();

        try {
            $response = $this->client->createTransactions($payloads);
        } catch (YnabAuthException $e) {
            throw $e;
        } catch (YnabException $e) {
            $counters['errors']++;
            foreach ($transactions as $transaction) {
                $transaction->push_attempt_count++;
                $transaction->last_push_attempt_at = $now;
                $transaction->last_push_error = $this->redact($e);
                $transaction->save();
                $this->logError($pushRun, $transaction, $this->classifyError($e), $e);
            }

            return;
        }

        /** @var list<array<string, mixed>> $created */
        $created = is_array($response['transactions'] ?? null) ? $response['transactions'] : [];
        /** @var list<string> $duplicates */
        $duplicates = is_array($response['duplicate_import_ids'] ?? null) ? $response['duplicate_import_ids'] : [];

        $resolved = [];

        foreach ($created as $createdTx) {
            $importId = isset($createdTx['import_id']) && is_string($createdTx['import_id']) ? $createdTx['import_id'] : null;
            $ynabId = isset($createdTx['id']) && is_string($createdTx['id']) ? $createdTx['id'] : null;
            if ($importId === null || $ynabId === null || ! isset($byImportId[$importId])) {
                continue;
            }
            $transaction = $byImportId[$importId];
            $transaction->status = TransactionStatus::Pushed;
            $transaction->ynab_transaction_id = $ynabId;
            $transaction->ynab_import_id = $importId;
            $transaction->pushed_at = $now;
            $transaction->last_push_attempt_at = $now;
            $transaction->push_attempt_count++;
            $transaction->save();

            $counters['pushed']++;
            $resolved[$importId] = true;
        }

        foreach ($duplicates as $duplicateImportId) {
            if (! isset($byImportId[$duplicateImportId])) {
                continue;
            }
            $transaction = $byImportId[$duplicateImportId];
            $transaction->status = TransactionStatus::Pushed;
            $transaction->ynab_import_id = $duplicateImportId;
            $transaction->pushed_at = $now;
            $transaction->last_push_attempt_at = $now;
            $transaction->push_attempt_count++;
            $transaction->save();

            $counters['duplicate']++;
            $resolved[$duplicateImportId] = true;
            Log::info('YNAB reported duplicate import_id; treating local row as pushed.', [
                'event' => 'push.duplicate_import_id',
                'bank_account_id' => $account->id,
                'import_id' => $duplicateImportId,
            ]);
        }

        // Transactions not reflected in either list — YNAB returned 201 but
        // silently dropped the row. Treat as a per-row push error so the run
        // surfaces error_count > 0 and operators see it in push_run_errors.
        foreach ($byImportId as $importId => $transaction) {
            if (isset($resolved[$importId])) {
                continue;
            }
            $transaction->push_attempt_count++;
            $transaction->last_push_attempt_at = $now;
            $transaction->last_push_error = 'YNAB response omitted this import_id.';
            $transaction->save();

            $counters['errors']++;
            PushRunError::query()->create([
                'push_run_id' => $pushRun->id,
                'transaction_id' => $transaction->id,
                'error_type' => PushErrorType::Other,
                'error_detail' => 'YNAB returned 201 but omitted this import_id from both transactions and duplicate_import_ids.',
                'http_status' => 201,
            ]);
        }
    }

    private function classifyError(YnabException $e): PushErrorType
    {
        $status = $e->httpStatus;

        return match (true) {
            $status === 401 => PushErrorType::Auth,
            $status === 429 => PushErrorType::RateLimit,
            $status !== null && $status >= 400 && $status < 500 => PushErrorType::Validation,
            $status !== null && $status >= 500 => PushErrorType::HttpError,
            default => PushErrorType::Network,
        };
    }

    private function redact(YnabException $e): string
    {
        return json_encode([
            'status' => $e->httpStatus,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $e->getMessage();
    }

    private function logError(PushRun $pushRun, ?Transaction $transaction, PushErrorType $type, \Throwable $e): void
    {
        PushRunError::query()->create([
            'push_run_id' => $pushRun->id,
            'transaction_id' => $transaction?->id,
            'error_type' => $type,
            'error_detail' => substr($e->getMessage(), 0, 1000),
            'http_status' => $e instanceof YnabException ? $e->httpStatus : null,
        ]);
    }

    /** @param  array{pushed:int,duplicate:int,errors:int}  $counters */
    private function finaliseRun(PushRun $pushRun, array $counters): void
    {
        $pushRun->finished_at = Carbon::now();
        $pushRun->transactions_pushed = $counters['pushed'];
        $pushRun->transactions_duplicate = $counters['duplicate'];
        $pushRun->error_count = $counters['errors'];
        $pushRun->save();
    }
}
