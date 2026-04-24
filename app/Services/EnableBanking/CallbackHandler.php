<?php

namespace App\Services\EnableBanking;

use App\Enums\BankConnectionStatus;
use App\Models\AuthRequest;
use App\Models\BankAccount;
use App\Models\BankAccountIdentifier;
use App\Models\BankAccountSession;
use App\Models\BankAccountSyncState;
use App\Models\BankConnection;
use App\Services\EnableBanking\Exceptions\EnableBankingException;
use App\Services\EnableBanking\Exceptions\InvalidCallbackStateException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates /banking/callback: validate state → exchange code → persist
 * session → upsert accounts via identification_hash matching (SPEC §4.4).
 *
 * Pure business logic — lives outside the controller so it's testable
 * without a live HTTP layer, and so the controller stays a thin adapter
 * that only handles rendering and status codes.
 */
class CallbackHandler
{
    public function __construct(private readonly Client $client) {}

    /**
     * @return array{connection: BankConnection, accounts: array<int, BankAccount>}
     *
     * @throws InvalidCallbackStateException
     * @throws EnableBankingException
     */
    public function handle(string $state, string $code): array
    {
        $authRequest = AuthRequest::query()
            ->where('state', $state)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (! $authRequest instanceof AuthRequest) {
            throw new InvalidCallbackStateException(
                "Callback state does not match an open auth_request (state={$state})."
            );
        }

        $session = $this->client->exchangeCode($code);

        if (! isset($session['session_id']) || ! is_string($session['session_id'])) {
            throw new RuntimeException('Enable Banking session response missing session_id.');
        }

        return DB::transaction(function () use ($authRequest, $session) {
            $authRequest->consumed_at = Carbon::now();
            $authRequest->save();

            $validUntil = Carbon::parse((string) ($session['access']['valid_until'] ?? Carbon::now()->addDays(90)->toIso8601String()));

            // Step 1: flip the prior active connection to superseded so the partial
            // unique index ("one active per bank_slug") releases. superseded_by_id
            // is backfilled once the new row exists.
            $prior = BankConnection::query()
                ->where('bank_slug', $authRequest->bank_slug)
                ->where('status', BankConnectionStatus::Active->value)
                ->first();

            if ($prior instanceof BankConnection) {
                $prior->status = BankConnectionStatus::Superseded;
                $prior->save();
            }

            // Step 2: insert new active connection.
            $connection = BankConnection::query()->create([
                'bank_slug' => $authRequest->bank_slug,
                'enable_banking_session_id' => $session['session_id'],
                'status' => BankConnectionStatus::Active,
                'authorized_at' => Carbon::now(),
                'valid_until' => $validUntil,
                'raw_session_response' => $session,
            ]);

            // Step 3: backfill superseded_by_id on the prior connection.
            if ($prior instanceof BankConnection) {
                $prior->superseded_by_id = $connection->id;
                $prior->save();
            }

            /** @var array<int, array<string, mixed>> $accounts */
            $accounts = is_array($session['accounts'] ?? null) ? $session['accounts'] : [];

            $discovered = [];
            foreach ($accounts as $account) {
                $discovered[] = $this->upsertAccount($authRequest->bank_slug, $connection, $account);
            }

            return [
                'connection' => $connection->refresh(),
                'accounts' => $discovered,
            ];
        });
    }

    /** @param  array<string, mixed>  $account */
    private function upsertAccount(string $bankSlug, BankConnection $connection, array $account): BankAccount
    {
        $primaryHash = (string) ($account['identification_hash'] ?? '');
        /** @var array<int, string> $allHashes */
        $allHashes = is_array($account['identification_hashes'] ?? null)
            ? array_values(array_filter($account['identification_hashes'], 'is_string'))
            : [];

        if ($primaryHash === '' && $allHashes === []) {
            throw new RuntimeException('Enable Banking account has no identification_hash — cannot proceed (SPEC §10.1).');
        }

        $lookupHashes = array_values(array_unique(array_filter(array_merge([$primaryHash], $allHashes))));

        $existing = BankAccountIdentifier::query()
            ->whereIn('hash', $lookupHashes)
            ->first();

        $currency = (string) ($account['currency'] ?? 'EUR');
        $baseCurrency = (string) config('spendula.base_currency', 'EUR');
        $isBaseCurrency = strtoupper($currency) === strtoupper($baseCurrency);

        $iban = null;
        if (isset($account['account_id']) && is_array($account['account_id'])) {
            $iban = isset($account['account_id']['iban']) && is_string($account['account_id']['iban'])
                ? $account['account_id']['iban']
                : null;
        }

        $now = Carbon::now();

        if ($existing instanceof BankAccountIdentifier) {
            $bankAccount = BankAccount::query()->findOrFail($existing->bank_account_id);
            $bankAccount->last_seen_at = $now;
            // IBAN may have materialised on a real bank after initial link against Mock — always refresh it.
            if ($iban !== null) {
                $bankAccount->iban = $iban;
            }
            $bankAccount->save();
        } else {
            $bankAccount = BankAccount::query()->create([
                'bank_slug' => $bankSlug,
                'iban' => $iban,
                'currency' => strtoupper($currency),
                'is_base_currency' => $isBaseCurrency,
                'active' => true,
                'first_linked_at' => $now,
                'last_seen_at' => $now,
            ]);
        }

        $this->syncIdentifiers($bankAccount, $primaryHash, $allHashes, $now);

        BankAccountSession::query()->updateOrCreate(
            [
                'bank_connection_id' => $connection->id,
                'bank_account_id' => $bankAccount->id,
            ],
            [
                'enable_banking_uid' => (string) ($account['uid'] ?? ''),
            ]
        );

        BankAccountSyncState::query()->firstOrCreate(
            ['bank_account_id' => $bankAccount->id],
            ['consecutive_failure_count' => 0],
        );

        return $bankAccount;
    }

    /** @param  array<int, string>  $allHashes */
    private function syncIdentifiers(BankAccount $account, string $primaryHash, array $allHashes, Carbon $now): void
    {
        $union = array_values(array_unique(array_filter(array_merge([$primaryHash], $allHashes))));

        if ($primaryHash !== '') {
            BankAccountIdentifier::query()
                ->where('bank_account_id', $account->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        foreach ($union as $hash) {
            $existing = BankAccountIdentifier::query()->where('hash', $hash)->first();

            if ($existing instanceof BankAccountIdentifier) {
                if ($existing->bank_account_id !== $account->id) {
                    throw new RuntimeException(
                        "identification_hash collision: hash {$hash} already owned by another bank_account."
                    );
                }
                $existing->is_primary = ($hash === $primaryHash);
                $existing->last_seen_at = $now;
                $existing->save();
            } else {
                BankAccountIdentifier::query()->create([
                    'bank_account_id' => $account->id,
                    'hash' => $hash,
                    'is_primary' => ($hash === $primaryHash),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            }
        }
    }

    public static function pruneExpiredAuthRequests(): void
    {
        AuthRequest::query()->where('created_at', '<', Carbon::now()->subDay())->delete();
    }
}
