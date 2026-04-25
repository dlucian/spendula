<?php

namespace App\Services\Sync;

use App\Enums\BankConnectionStatus;
use App\Enums\SyncErrorType;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankAccountSession;
use App\Models\BankAccountSyncState;
use App\Models\BankConnection;
use App\Models\SyncRun;
use App\Models\SyncRunError;
use App\Services\EnableBanking\Client;
use App\Services\EnableBanking\Exceptions\EnableBankingAuthException;
use App\Services\EnableBanking\Exceptions\EnableBankingException;
use App\Services\EnableBanking\Exceptions\EnableBankingRateLimitException;
use App\Services\EnableBanking\Exceptions\EnableBankingRevokedException;
use App\Services\EnableBanking\Exceptions\EnableBankingServerException;
use App\Services\Locks\AdvisoryLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates spendula:sync. SPEC §6.1, §6.6, §10.1.
 *
 * For every active bank_connection, iterate its bank_account_sessions,
 * determine the fetch window (§6.2), paginate transactions, and hand each
 * BOOK transaction to MatchUpdateOrInsert. Persist the sync state after
 * every page so an interrupted run resumes cleanly (§6.6). Pagination is
 * capped at 50 pages per account as a defensive guard against loops.
 *
 * Error handling follows SPEC §10.1: 401 is a hard fail, 403 revokes the
 * connection, 429 and 5xx abort this account cleanly and continue with
 * the next.
 *
 * Tracking accounts are explicitly out of scope in phase 1 (they use
 * balance snapshots in phase 3); this runner skips them.
 */
class SyncRunner
{
    /** Defensive cap against misbehaving continuation_key pagination. */
    private const int MAX_PAGES_PER_ACCOUNT = 50;

    public function __construct(
        private readonly Client $client,
        private readonly MatchUpdateOrInsert $matchUpdateOrInsert,
    ) {}

    public function run(?string $bankSlug = null): SyncResult
    {
        /** @var SyncResult $result */
        $result = AdvisoryLock::withLock(
            AdvisoryLock::SYNC,
            fn (): SyncResult => $this->runLocked($bankSlug),
        );

        return $result;
    }

    private function runLocked(?string $bankSlug): SyncResult
    {
        // Validate the slug before creating sync_runs row — sync_runs.bank_slug
        // is FK-enforced, so an unknown slug from --bank=<typo> would otherwise
        // surface as a raw QueryException instead of a clean operator error.
        if ($bankSlug !== null && ! Bank::query()->where('slug', $bankSlug)->exists()) {
            throw new \InvalidArgumentException("No bank with slug '{$bankSlug}'. Run `php artisan spendula:banks:sync` to refresh the list.");
        }

        $syncRun = SyncRun::query()->create([
            'bank_slug' => $bankSlug,
            'started_at' => Carbon::now(),
        ]);

        $counters = ['inserted' => 0, 'updated' => 0, 'deduped' => 0, 'errors' => 0];

        // Honor `banks.active` so an operator who removes a bank from
        // config/spendula-banks.php (banks:sync flips active=false) actually
        // stops pulling transactions from that bank. Filtering only on
        // bank_connections.status would leave the connection live until the
        // operator manually revoked it.
        $connections = BankConnection::query()
            ->where('status', BankConnectionStatus::Active->value)
            ->whereHas('bank', function ($q): void {
                /** @var Builder<Bank> $q */
                $q->where('active', true);
            })
            ->when($bankSlug !== null, fn ($q) => $q->where('bank_slug', $bankSlug))
            ->with(['bank'])
            ->get();

        foreach ($connections as $connection) {
            try {
                $this->syncConnection($connection, $syncRun, $counters);
            } catch (EnableBankingAuthException $e) {
                $this->logError($syncRun, null, SyncErrorType::Other, $e);
                $counters['errors']++;
                // Hard fail per SPEC §10.1 — propagate so the caller exits non-zero.
                $this->finaliseRun($syncRun, $counters);
                throw $e;
            }
        }

        $this->finaliseRun($syncRun, $counters);

        return new SyncResult($syncRun->refresh(), $counters['inserted'], $counters['updated'], $counters['deduped'], $counters['errors']);
    }

    /** @param  array{inserted:int,updated:int,deduped:int,errors:int}  $counters */
    private function syncConnection(BankConnection $connection, SyncRun $syncRun, array &$counters): void
    {
        $bank = $connection->bank;
        if (! $bank instanceof Bank) {
            return;
        }

        // Local consent-expiry check before any HTTP. Without this, the first
        // post-expiry sync still hits EB and a 403 there gets classified as
        // Revoked, which is wrong: the consent expired naturally, the user
        // didn't revoke it. SPEC §10.1 wants this transitioned to expired and
        // recorded as a consent_expired error.
        if ($connection->valid_until->isPast()) {
            $connection->status = BankConnectionStatus::Expired;
            $connection->save();
            $this->logError(
                $syncRun,
                null,
                SyncErrorType::ConsentExpired,
                new \RuntimeException("Bank connection valid_until ({$connection->valid_until->toIso8601String()}) has already passed."),
            );
            $counters['errors']++;

            return;
        }

        /** @var Collection<int, BankAccountSession> $sessions */
        $sessions = BankAccountSession::query()
            ->where('bank_connection_id', $connection->id)
            ->with(['bankAccount.syncState'])
            ->get();

        // last_synced_at represents "this whole connection synced cleanly". Any
        // per-account failure (throw OR non-throwing false from syncAccount)
        // disqualifies the connection — otherwise spendula:status would show a
        // recently-synced connection with hidden sync_run_errors.
        $accountsAttempted = 0;
        $accountsFailed = 0;

        foreach ($sessions as $session) {
            $account = $session->bankAccount;
            if (! $account instanceof BankAccount || ! $account->active) {
                continue;
            }

            // Tracking accounts use snapshots (phase 3); phase 1 skips them.
            if ($account->ynab_account_type === YnabAccountType::Tracking) {
                continue;
            }

            $accountsAttempted++;

            try {
                if (! $this->syncAccount($connection, $bank, $session, $account, $syncRun, $counters)) {
                    // Non-throwing failure (malformed-200, stalled continuation_key,
                    // 50-page cap) — sync_run_error already logged inside.
                    $accountsFailed++;
                }
            } catch (EnableBankingRevokedException $e) {
                $connection->status = BankConnectionStatus::Revoked;
                $connection->save();
                $this->logError($syncRun, $account, SyncErrorType::ConsentExpired, $e);
                $counters['errors']++;

                return; // all accounts on this connection are now moot
            } catch (EnableBankingRateLimitException $e) {
                $this->logError($syncRun, $account, SyncErrorType::RateLimit, $e);
                $counters['errors']++;
                $accountsFailed++;

                // Continuation key already persisted by inner loop; move to next account.
                continue;
            } catch (EnableBankingServerException $e) {
                $this->logError($syncRun, $account, SyncErrorType::HttpError, $e);
                $counters['errors']++;
                $accountsFailed++;

                continue;
            } catch (EnableBankingException $e) {
                $this->logError($syncRun, $account, SyncErrorType::HttpError, $e);
                $counters['errors']++;
                $accountsFailed++;

                continue;
            } catch (Throwable $e) {
                $this->logError($syncRun, $account, SyncErrorType::Other, $e);
                $counters['errors']++;
                $accountsFailed++;
                Log::warning('Sync threw unexpectedly', [
                    'event' => 'sync.account.failed',
                    'bank_slug' => $bank->slug,
                    'bank_account_id' => $account->id,
                ]);

                continue;
            }
        }

        if ($accountsAttempted > 0 && $accountsFailed === 0) {
            $connection->last_synced_at = Carbon::now();
            $connection->save();
        }
    }

    /**
     * @param  array{inserted:int,updated:int,deduped:int,errors:int}  $counters
     *
     * @throws EnableBankingException
     */
    private function syncAccount(
        BankConnection $connection,
        Bank $bank,
        BankAccountSession $session,
        BankAccount $account,
        SyncRun $syncRun,
        array &$counters,
    ): bool {
        $syncState = $account->syncState instanceof BankAccountSyncState
            ? $account->syncState
            : BankAccountSyncState::query()->firstOrCreate(
                ['bank_account_id' => $account->id],
                ['consecutive_failure_count' => 0],
            );

        $dateFrom = $this->computeDateFrom($bank, $syncState);
        $continuationKey = $syncState->last_continuation_key;
        $pagesVisited = 0;

        do {
            if ($pagesVisited >= self::MAX_PAGES_PER_ACCOUNT) {
                $this->logError(
                    $syncRun,
                    $account,
                    SyncErrorType::ParseError,
                    new \RuntimeException('Aborted after 50 pages — continuation_key pagination suspected loop.'),
                );
                $counters['errors']++;

                // Persist failure state so spendula:status reflects the abort —
                // otherwise consecutive_failure_count never increments and an
                // account stuck in a continuation_key loop looks healthy.
                $syncState->last_continuation_key = $continuationKey;
                $syncState->last_sync_error_at = Carbon::now();
                $syncState->consecutive_failure_count++;
                $syncState->save();

                return false;
            }

            try {
                $response = $this->client->accountTransactions(
                    $session->enable_banking_uid,
                    $dateFrom,
                    $continuationKey,
                );
            } catch (EnableBankingException $e) {
                // Persist progress before bubbling up so the next run resumes here.
                $syncState->last_continuation_key = $continuationKey;
                $syncState->last_sync_error_at = Carbon::now();
                $syncState->consecutive_failure_count++;
                $syncState->save();

                throw $e;
            }

            $pagesVisited++;

            // EB returning HTTP 200 without a `transactions` array is not the same
            // as "no new transactions" (which is `transactions: []`). Treat the
            // missing/non-array shape as a parse_error so operators see the failure
            // instead of a silent zero-import sync.
            if (! array_key_exists('transactions', $response) || ! is_array($response['transactions'])) {
                $this->logError(
                    $syncRun,
                    $account,
                    SyncErrorType::ParseError,
                    new \RuntimeException('EB returned 200 but the page omitted or malformed the transactions array.'),
                );
                $counters['errors']++;

                $syncState->last_continuation_key = $continuationKey;
                $syncState->last_sync_error_at = Carbon::now();
                $syncState->consecutive_failure_count++;
                $syncState->save();

                return false;
            }

            $transactions = $response['transactions'];
            $maxBookingDate = null;

            foreach ($transactions as $ebTransaction) {
                if (! is_array($ebTransaction)) {
                    // A non-array element inside `transactions` is a parse-level
                    // failure of the page. Persist failure state and abort the
                    // account: continuing would advance last_continuation_key
                    // past the malformed page so the bad row could never be
                    // recovered, while the missing-`transactions`-array branch
                    // above already aborts the page on the same severity.
                    $this->logError(
                        $syncRun,
                        $account,
                        SyncErrorType::ParseError,
                        new \RuntimeException('EB returned a non-array element inside transactions[].'),
                    );
                    $counters['errors']++;

                    $syncState->last_continuation_key = $continuationKey;
                    $syncState->last_sync_error_at = Carbon::now();
                    $syncState->consecutive_failure_count++;
                    $syncState->save();

                    return false;
                }
                $status = isset($ebTransaction['transaction_status']) && is_string($ebTransaction['transaction_status'])
                    ? $ebTransaction['transaction_status']
                    : '';
                if ($status !== 'BOOK') {
                    continue;
                }

                try {
                    $result = $this->matchUpdateOrInsert->apply($account, $ebTransaction);
                } catch (\InvalidArgumentException $e) {
                    // A malformed BOOK item (missing transaction_amount,
                    // booking_date, unknown CDI, etc.) is a parse-level page
                    // failure: continuing past it would advance
                    // last_continuation_key, after which the 7-day overlap
                    // window is the only chance to recover the row — fixing
                    // the parser later cannot reach it. Persist failure
                    // metadata and abort the account so the page remains
                    // resumable until the operator addresses it.
                    $this->logError($syncRun, $account, SyncErrorType::ParseError, $e);
                    $counters['errors']++;

                    $syncState->last_continuation_key = $continuationKey;
                    $syncState->last_sync_error_at = Carbon::now();
                    $syncState->consecutive_failure_count++;
                    $syncState->save();

                    return false;
                }

                match ($result->outcome) {
                    ApplyOutcome::Inserted => $counters['inserted']++,
                    ApplyOutcome::Updated => $counters['updated']++,
                    ApplyOutcome::Deduped => $counters['deduped']++,
                };

                $bookingDate = $result->transaction->booking_date;
                if ($maxBookingDate === null || $bookingDate->greaterThan($maxBookingDate)) {
                    $maxBookingDate = $bookingDate;
                }
            }

            // Persist page-level progress before fetching the next page.
            if ($maxBookingDate !== null) {
                if ($syncState->last_fetched_through === null || $maxBookingDate->greaterThan($syncState->last_fetched_through)) {
                    $syncState->last_fetched_through = $maxBookingDate;
                }
            }

            $nextContinuationKey = isset($response['continuation_key']) && is_string($response['continuation_key'])
                ? $response['continuation_key']
                : null;

            if ($nextContinuationKey !== null && $nextContinuationKey === $continuationKey) {
                $this->logError(
                    $syncRun,
                    $account,
                    SyncErrorType::ParseError,
                    new \RuntimeException('continuation_key did not advance between pages.'),
                );
                $counters['errors']++;

                // Persist failure state so a stalled-key loop surfaces in
                // spendula:status; without this consecutive_failure_count never
                // increments and the operator can't tell the account is stuck.
                $syncState->last_continuation_key = $continuationKey;
                $syncState->last_sync_error_at = Carbon::now();
                $syncState->consecutive_failure_count++;
                $syncState->save();

                return false;
            }

            $syncState->last_continuation_key = $nextContinuationKey;
            $syncState->save();

            $continuationKey = $nextContinuationKey;
        } while ($continuationKey !== null && $continuationKey !== '');

        // Clean finish — clear resume state and reset failure counter.
        $syncState->last_continuation_key = null;
        $syncState->last_successful_sync_at = Carbon::now();
        $syncState->consecutive_failure_count = 0;
        $syncState->save();

        return true;
    }

    /** SPEC §6.2 fetch window. */
    private function computeDateFrom(Bank $bank, BankAccountSyncState $syncState): string
    {
        $lookbackCap = Carbon::today()->subDays($bank->sync_lookback_days);

        if ($syncState->last_fetched_through === null) {
            return $lookbackCap->toDateString();
        }

        $overlapStart = $syncState->last_fetched_through->copy()->subDays(7);

        return $overlapStart->greaterThan($lookbackCap)
            ? $overlapStart->toDateString()
            : $lookbackCap->toDateString();
    }

    private function logError(SyncRun $syncRun, ?BankAccount $account, SyncErrorType $type, Throwable $e): void
    {
        $httpStatus = $e instanceof EnableBankingException ? $e->httpStatus : null;

        SyncRunError::query()->create([
            'sync_run_id' => $syncRun->id,
            'bank_account_id' => $account?->id,
            'error_type' => $type,
            'error_detail' => substr($e->getMessage(), 0, 1000),
            'http_status' => $httpStatus,
        ]);
    }

    /** @param  array{inserted:int,updated:int,deduped:int,errors:int}  $counters */
    private function finaliseRun(SyncRun $syncRun, array $counters): void
    {
        $syncRun->finished_at = Carbon::now();
        $syncRun->transactions_inserted = $counters['inserted'];
        $syncRun->transactions_updated = $counters['updated'];
        $syncRun->transactions_deduped = $counters['deduped'];
        $syncRun->error_count = $counters['errors'];
        $syncRun->save();
    }
}
