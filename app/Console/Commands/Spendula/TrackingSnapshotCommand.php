<?php

namespace App\Console\Commands\Spendula;

use App\Enums\BankConnectionStatus;
use App\Enums\YnabAccountType;
use App\Models\BankAccount;
use App\Models\BankAccountSession;
use App\Models\BankConnection;
use App\Models\TrackingSnapshot;
use App\Services\EnableBanking\Client as EnableBankingClient;
use App\Services\EnableBanking\Exceptions\EnableBankingException;
use App\Services\ExchangeRates\Exceptions\ExchangeRateProviderUnreachableException;
use App\Services\ExchangeRates\Rate;
use App\Services\ExchangeRates\RateProvider;
use App\Services\Locks\AdvisoryLock;
use App\Services\Locks\LockBusyException;
use App\Services\Money\Money;
use App\Services\Ynab\Client as YnabClient;
use App\Services\Ynab\Exceptions\YnabAuthException;
use App\Services\Ynab\Exceptions\YnabException;
use App\Services\Ynab\Exceptions\YnabRateLimitException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

#[Signature('spendula:tracking:snapshot
    {--account= : Spendula bank_accounts.id (UUID); omit to snapshot all active tracking accounts}
    {--dry-run : Compute and display deltas without pushing to YNAB or recording snapshots}
')]
#[Description('Compute and push tracking-account balance snapshots to YNAB (SPEC §5.3).')]
class TrackingSnapshotCommand extends Command
{
    /**
     * Implements SPEC §5.3 tracking-account lifecycle.
     *
     * Walks every active tracking-mapped bank_account (or the single one
     * passed via --account=<spendula-uuid>), fetches the live native-currency
     * balance from Enable Banking, converts to EUR via the configured
     * RateProvider, fetches the current YNAB account balance, and pushes a
     * single Balance Adjustment transaction to YNAB equal to the delta. One
     * tracking_snapshots row is recorded per pushed delta.
     *
     * Failure: lock contention exits 1 with a clear message; rate-provider
     *   unreachable exits 1 (SPEC §5.5 — never proceed with stale FX); YNAB
     *   auth/rate-limit exit 1 with operator instructions. Per-account
     *   Enable Banking failures isolate to that account — exit 0 if at
     *   least one account succeeded, exit 1 if all failed.
     *
     * Idempotency: SPEC §5.4 — operator-driven cadence. A second run on
     *   the same day produces a second tracking_snapshots row with delta
     *   ≈ 0; the command does not check for prior same-day snapshots.
     *
     * Concurrency: holds AdvisoryLock::TRACKING_SNAPSHOT for the entire run.
     */
    public function handle(
        RateProvider $rateProvider,
        EnableBankingClient $ebClient,
        YnabClient $ynabClient,
    ): int {
        try {
            return AdvisoryLock::withLock(
                AdvisoryLock::TRACKING_SNAPSHOT,
                fn (): int => $this->runLocked($rateProvider, $ebClient, $ynabClient),
            );
        } catch (LockBusyException) {
            $this->error('Another tracking snapshot is already running.');

            return self::FAILURE;
        }
    }

    private function runLocked(
        RateProvider $rateProvider,
        EnableBankingClient $ebClient,
        YnabClient $ynabClient,
    ): int {
        $accountFilter = (string) $this->option('account');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $accounts = $this->resolveAccounts($accountFilter);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($accounts === []) {
            $this->info('No active tracking accounts to snapshot.');

            return self::SUCCESS;
        }

        $today = Carbon::today();
        $baseCurrency = strtoupper((string) config('spendula.base_currency', 'EUR'));

        $succeeded = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $this->snapshotAccount(
                    $account,
                    $today,
                    $baseCurrency,
                    $rateProvider,
                    $ebClient,
                    $ynabClient,
                    $dryRun,
                );
                $succeeded++;
            } catch (ExchangeRateProviderUnreachableException $e) {
                // Rate-provider unreachable is a global hard fail (SPEC §5.5):
                // every remaining account would hit the same wall, and we
                // explicitly must not proceed with a stale rate. Abort the
                // whole run rather than per-account isolate.
                $this->error('Exchange rate provider unreachable; no snapshot taken.');
                $this->warn($e->getMessage());

                return self::FAILURE;
            } catch (YnabAuthException $e) {
                $this->error('YNAB rejected the access token: '.$e->getMessage());
                $this->warn('Fix SPENDULA_YNAB_ACCESS_TOKEN in .env, then re-run.');

                return self::FAILURE;
            } catch (YnabRateLimitException $e) {
                $this->error('YNAB returned 429 Too Many Requests: '.$e->getMessage());
                $this->warn('SPEC §10.2 aborts the run on rate-limit; re-run after a short wait.');

                return self::FAILURE;
            } catch (EnableBankingException $e) {
                // Per-account isolation (matches PushRunner::pushGroup): a
                // single account's EB failure shouldn't block the others in
                // the same run.
                $this->warn(sprintf(
                    '[tracking-snapshot] account=%s skipped: Enable Banking unreachable: %s',
                    $account->id,
                    $e->getMessage(),
                ));
                Log::warning('tracking-snapshot: Enable Banking failure', [
                    'event' => 'tracking_snapshot.eb_failure',
                    'bank_account_id' => $account->id,
                    'message' => $e->getMessage(),
                    'http_status' => $e->httpStatus,
                ]);
                $failed++;
            } catch (YnabException $e) {
                $this->warn(sprintf(
                    '[tracking-snapshot] account=%s skipped: YNAB error: %s',
                    $account->id,
                    $e->getMessage(),
                ));
                Log::warning('tracking-snapshot: YNAB failure', [
                    'event' => 'tracking_snapshot.ynab_failure',
                    'bank_account_id' => $account->id,
                    'message' => $e->getMessage(),
                    'http_status' => $e->httpStatus,
                ]);
                $failed++;
            } catch (Throwable $e) {
                $this->warn(sprintf(
                    '[tracking-snapshot] account=%s skipped: %s',
                    $account->id,
                    $e->getMessage(),
                ));
                Log::warning('tracking-snapshot: unexpected failure', [
                    'event' => 'tracking_snapshot.unexpected_failure',
                    'bank_account_id' => $account->id,
                    'message' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        // Exit non-zero only when every attempted account failed. Mirrors
        // PushRunner: partial success surfaces as exit 0 with per-account
        // warnings already logged above.
        if ($succeeded === 0 && $failed > 0) {
            $this->error(sprintf('tracking-snapshot: 0 succeeded, %d failed.', $failed));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'tracking-snapshot: succeeded=%d failed=%d dry_run=%s',
            $succeeded,
            $failed,
            $dryRun ? 'true' : 'false',
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<BankAccount>
     *
     * @throws InvalidArgumentException when --account references a row that
     *                                  doesn't exist, isn't tracking-mapped, or is inactive.
     */
    private function resolveAccounts(string $accountFilter): array
    {
        if ($accountFilter !== '') {
            /** @var BankAccount|null $account */
            $account = BankAccount::query()->where('id', $accountFilter)->first();
            if (! $account instanceof BankAccount) {
                throw new InvalidArgumentException("No bank_account with id '{$accountFilter}'.");
            }
            if (! $account->active) {
                throw new InvalidArgumentException("bank_account '{$accountFilter}' is inactive.");
            }
            if ($account->ynab_account_type !== YnabAccountType::Tracking) {
                throw new InvalidArgumentException(
                    "bank_account '{$accountFilter}' is not tracking-mapped (ynab_account_type="
                    .($account->ynab_account_type !== null ? $account->ynab_account_type->value : 'null').')'
                );
            }
            if ($account->ynab_account_id === null) {
                throw new InvalidArgumentException("bank_account '{$accountFilter}' has no ynab_account_id.");
            }

            return [$account];
        }

        /** @var list<BankAccount> $accounts */
        $accounts = BankAccount::query()
            ->where('ynab_account_type', YnabAccountType::Tracking->value)
            ->whereNotNull('ynab_account_id')
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->all();

        return $accounts;
    }

    /**
     * @throws ExchangeRateProviderUnreachableException
     * @throws EnableBankingException
     * @throws YnabException
     */
    private function snapshotAccount(
        BankAccount $account,
        Carbon $today,
        string $baseCurrency,
        RateProvider $rateProvider,
        EnableBankingClient $ebClient,
        YnabClient $ynabClient,
        bool $dryRun,
    ): void {
        $session = $this->resolveActiveSession($account);
        if ($session === null) {
            $this->warn(sprintf(
                '[tracking-snapshot] account=%s skipped: no active bank_account_session.',
                $account->id,
            ));

            // Treat this as a per-account skip — not a hard error. Mirrors
            // SyncRunner's session-missing behavior. Exit code is decided
            // by the caller's succeeded/failed accounting; throw a typed
            // exception so the caller increments `failed` consistently.
            throw new EnableBankingException(
                "no active bank_account_session for bank_account_id={$account->id}",
            );
        }

        $balancesResponse = $ebClient->accountBalances($session->enable_banking_uid);
        [$amount, $cdi, $balanceType] = $this->pickBalance($balancesResponse, $account);

        $nativeMilliunits = Money::toMilliunits($amount, $cdi);

        $rate = $rateProvider->getRate(strtoupper($account->currency), $baseCurrency, $today);

        // SPEC §5.5: convert via bcmath, truncate toward zero at the
        // milliunit boundary. bcmul with scale 0 produces an integer-string
        // already; the (int) cast just types it. Direction matches
        // Money::toMilliunits.
        $rateString = $rate->rate;
        if (! is_numeric($rateString)) {
            throw new \RuntimeException("RateProvider returned non-numeric rate string: {$rateString}");
        }
        $expectedBaseMilliunits = (int) bcmul((string) $nativeMilliunits, $rateString, 0);

        $ynabAccount = $ynabClient->account((string) $account->ynab_account_id);
        $currentYnabMilliunits = $this->extractYnabBalance($ynabAccount, $account);

        $delta = $expectedBaseMilliunits - $currentYnabMilliunits;

        if ($dryRun) {
            $this->line(sprintf(
                '[tracking-snapshot] account=%s native=%s %s rate=%s (as of %s) expected_%s=%s ynab_balance=%s delta=%d (eb_balance_type=%s)',
                $account->id,
                Money::format($nativeMilliunits, $account->currency),
                $account->currency,
                $rate->rate,
                $rate->rateDate->toDateString(),
                strtolower($baseCurrency),
                Money::format($expectedBaseMilliunits, $baseCurrency),
                Money::format($currentYnabMilliunits, $baseCurrency),
                $delta,
                $balanceType,
            ));

            return;
        }

        // Stable import_id (SPEC §7.3 shape: "SPNDL:" + sha1[:30] = 36 chars).
        // Pinned so an HTTP-layer retry inside requestJson() — i.e. the
        // network blipped after YNAB already accepted the POST — re-sends
        // the same payload and YNAB dedups via duplicate_import_ids
        // instead of creating a second Balance Adjustment for the same
        // delta. Inputs are deterministic across retries within a single
        // snapshot but differ across separate runs (current_ynab_balance
        // shifts after the first push lands), so operator-driven re-runs
        // still produce fresh transactions per SPEC §5.4.
        $importIdInput = implode('|', [
            'TRACKING_SNAPSHOT',
            (string) $account->id,
            $today->toDateString(),
            (string) $expectedBaseMilliunits,
            (string) $currentYnabMilliunits,
        ]);
        $importId = 'SPNDL:'.substr(sha1($importIdInput), 0, 30);

        $payload = [
            'account_id' => $account->ynab_account_id,
            'date' => $today->toDateString(),
            'amount' => $delta,
            'payee_name' => 'Balance Adjustment',
            'memo' => sprintf(
                'FX snapshot: %s %s, rate %s, as of %s',
                Money::format($nativeMilliunits, $account->currency),
                $account->currency,
                $rate->rate,
                $rate->rateDate->toDateString(),
            ),
            'cleared' => 'reconciled',
            'approved' => true,
            'import_id' => $importId,
        ];

        $response = $ynabClient->createTransactions([$payload]);
        $ynabTransactionId = $this->extractCreatedTransactionId($response);

        Log::info('tracking-snapshot: pushed Balance Adjustment to YNAB', [
            'event' => 'tracking_snapshot.pushed',
            'bank_account_id' => $account->id,
            'ynab_transaction_id' => $ynabTransactionId,
            'eb_balance_type' => $balanceType,
            'delta_milliunits' => $delta,
        ]);

        $snapshot = TrackingSnapshot::query()->create([
            'bank_account_id' => $account->id,
            'as_of_date' => $today->toDateString(),
            'native_balance_milliunits' => $nativeMilliunits,
            'base_balance_milliunits' => $expectedBaseMilliunits,
            'exchange_rate' => $rate->rate,
            'exchange_rate_source' => $rate->source,
            'ynab_transaction_id' => $ynabTransactionId,
            'pushed_at' => Carbon::now(),
        ]);

        $this->info(sprintf(
            '[tracking-snapshot] account=%s snapshot_id=%s ynab_transaction_id=%s delta=%d milliunits',
            $account->id,
            $snapshot->id,
            $ynabTransactionId,
            $delta,
        ));
    }

    /**
     * Resolve the bank_account_session bound to the most recently active
     * BankConnection for this account. Returns null when none exists.
     */
    private function resolveActiveSession(BankAccount $account): ?BankAccountSession
    {
        /** @var BankAccountSession|null $session */
        $session = BankAccountSession::query()
            ->where('bank_account_id', $account->id)
            ->whereIn(
                'bank_connection_id',
                BankConnection::query()
                    ->where('status', BankConnectionStatus::Active->value)
                    ->select('id'),
            )
            ->orderByDesc('id')
            ->first();

        return $session;
    }

    /**
     * Pick the balance entry to anchor the snapshot on. SPEC §5.3 doesn't
     * pin a specific `balance_type`; this method prefers `interim_available`
     * (the live current balance, the most-comparable to YNAB's "what's
     * actually there"), falling back to `expected` and finally the first
     * entry. The picked type is logged so operators can audit per-bank
     * variation. Recorded in app/Console/Commands/Spendula/DECISIONS.md.
     *
     * @param  array<string, mixed>  $response
     * @return array{0: string, 1: string, 2: string} amount, credit_debit_indicator, balance_type
     */
    private function pickBalance(array $response, BankAccount $account): array
    {
        $balances = $response['balances'] ?? null;
        if (! is_array($balances) || $balances === []) {
            throw new EnableBankingException(
                "Enable Banking returned no balances for bank_account_id={$account->id}",
            );
        }

        // Two type vocabularies coexist in real EB responses: the canonical
        // lowercase form (`interim_available`, `expected`, `closing_booked`)
        // and the Berlin Group ISO 20022 codes the upstream ASPSP emits
        // unchanged (`ITAV`, `XPCD`, `CLBD`). Real Revolut returns ITAV;
        // real ING returns XPCD. Index by both shapes so the preference
        // ladder works regardless of which form arrives.
        /** @var array<string, array<string, mixed>> $byType */
        $byType = [];
        foreach ($balances as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $type = isset($entry['balance_type']) && is_string($entry['balance_type'])
                ? $entry['balance_type']
                : '';
            if ($type !== '') {
                $byType[$type] = $entry;
            }
        }

        $picked = $byType['interim_available']
            ?? $byType['ITAV']
            ?? $byType['expected']
            ?? $byType['XPCD']
            ?? $byType['closing_booked']
            ?? $byType['CLBD']
            ?? null;

        if ($picked === null) {
            $first = $balances[0] ?? null;
            if (! is_array($first)) {
                throw new EnableBankingException(
                    "Enable Banking returned malformed balances for bank_account_id={$account->id}",
                );
            }
            $picked = $first;
        }

        $amountNode = $picked['balance_amount'] ?? null;
        if (! is_array($amountNode)) {
            throw new EnableBankingException(
                "Enable Banking balance entry missing balance_amount for bank_account_id={$account->id}",
            );
        }

        $amount = isset($amountNode['amount']) && is_string($amountNode['amount']) ? $amountNode['amount'] : null;
        if ($amount === null || $amount === '') {
            throw new EnableBankingException(
                "Enable Banking balance entry missing amount string for bank_account_id={$account->id}",
            );
        }
        // Berlin Group amounts may carry an explicit leading sign. Money::toMilliunits
        // accepts an optional `-` but not `+`, so strip a leading `+` here. Sign
        // inference below then runs on the canonical form.
        $amount = ltrim($amount);
        if (str_starts_with($amount, '+')) {
            $amount = substr($amount, 1);
        }

        // EB sometimes returns balances in a currency that doesn't match the
        // bank_account currency (multi-currency accounts, edge ASPSPs). Pushing
        // the FX-converted delta with the wrong source currency would silently
        // corrupt the YNAB Balance Adjustment. Treat as a malformed response.
        $balanceCurrency = isset($amountNode['currency']) && is_string($amountNode['currency'])
            ? strtoupper($amountNode['currency'])
            : null;
        if ($balanceCurrency === null || $balanceCurrency === '') {
            throw new EnableBankingException(
                "Enable Banking balance entry missing currency string for bank_account_id={$account->id}",
            );
        }
        $accountCurrency = strtoupper($account->currency);
        if ($balanceCurrency !== $accountCurrency) {
            throw new EnableBankingException(
                "Enable Banking balance currency mismatch for bank_account_id={$account->id}: expected {$accountCurrency}, got {$balanceCurrency}",
            );
        }

        // Per Berlin Group convention, balance entries either carry an
        // explicit `credit_debit_indicator` (CRDT/DBIT) OR a signed amount
        // string. Real Revolut and ING omit the field and rely on the
        // amount's sign; our prior strict requirement made the command
        // unusable on every real ASPSP we'd seen. Strategy:
        //   - explicit CDI present  → require it to be CRDT/DBIT (case-
        //     insensitive); reject garbage so a typo never silently flips
        //     the sign.
        //   - explicit CDI absent   → infer from the amount sign. Negative
        //     amount → DBIT, otherwise CRDT. Money::toMilliunits will then
        //     strip the sign and re-apply via the inferred CDI.
        $cdiPresent = array_key_exists('credit_debit_indicator', $picked);
        if ($cdiPresent) {
            $cdiRaw = is_string($picked['credit_debit_indicator'])
                ? strtoupper($picked['credit_debit_indicator'])
                : null;
            if ($cdiRaw !== 'CRDT' && $cdiRaw !== 'DBIT') {
                throw new EnableBankingException(
                    "Enable Banking balance entry has invalid credit_debit_indicator for bank_account_id={$account->id}",
                );
            }
            $cdi = $cdiRaw;
        } else {
            $cdi = str_starts_with($amount, '-') ? 'DBIT' : 'CRDT';
        }

        $type = isset($picked['balance_type']) && is_string($picked['balance_type'])
            ? $picked['balance_type']
            : 'unknown';

        return [$amount, $cdi, $type];
    }

    /**
     * @param  array<string, mixed>  $ynabAccount
     */
    private function extractYnabBalance(array $ynabAccount, BankAccount $account): int
    {
        // YnabClient::classify auto-unwraps the {data: …} envelope. The
        // remaining structure is {account: {balance: int, …}}.
        $accountNode = $ynabAccount['account'] ?? null;
        if (! is_array($accountNode)) {
            throw new YnabException(
                "YNAB account response missing 'account' for bank_account_id={$account->id}",
            );
        }
        $balance = $accountNode['balance'] ?? null;
        if (! is_int($balance)) {
            throw new YnabException(
                "YNAB account response missing integer 'balance' for bank_account_id={$account->id}",
            );
        }

        return $balance;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractCreatedTransactionId(array $response): string
    {
        $transactions = $response['transactions'] ?? null;
        if (is_array($transactions) && isset($transactions[0]) && is_array($transactions[0])) {
            $id = $transactions[0]['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new YnabException('YNAB createTransactions response missing transactions[0].id.');
            }

            return $id;
        }

        // YNAB legitimately omits `transactions` and returns only
        // `duplicate_import_ids` when it dedups a retry — the transaction
        // already exists from the first attempt. Surface the import id back
        // so the snapshot row can still record a stable identifier instead
        // of crashing on the second push.
        $duplicateImportIds = $response['duplicate_import_ids'] ?? null;
        if (is_array($duplicateImportIds)) {
            foreach ($duplicateImportIds as $duplicateImportId) {
                if (is_string($duplicateImportId) && $duplicateImportId !== '') {
                    return $duplicateImportId;
                }
            }
        }

        throw new YnabException(
            'YNAB createTransactions response missing both transactions[0].id and duplicate_import_ids.'
        );
    }
}
