<?php

namespace App\Services\EnableBanking;

use App\Enums\BankConnectionStatus;
use App\Models\AuthRequest;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankAccountIdentifier;
use App\Models\BankAccountSession;
use App\Models\BankAccountSyncState;
use App\Models\BankConnection;
use App\Services\EnableBanking\Exceptions\EnableBankingException;
use App\Services\EnableBanking\Exceptions\InvalidCallbackStateException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * @throws RuntimeException for malformed-but-200 EB session payloads (missing
     *                          session_id, missing accounts array, missing account uid or
     *                          identification_hash). Account-level RuntimeExceptions roll back the
     *                          connection insert and supersession atomically — no partial state.
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

        // Local preflight: sign and discard a JWT so a missing app id or unreadable
        // private key surfaces here (still recoverable: fix config and retry the
        // same callback) instead of after we've already marked the row consumed
        // and the EB code is irrecoverable.
        $this->client->preflight();

        // Now mark the row consumed BEFORE the actual exchange. The conditional
        // update is a race guard: concurrent callbacks for the same state can't
        // both pass the whereNull check. If exchangeCode or any later step fails
        // after this point, the row reflects that the one-time code was spent on
        // the wire, so the operator restarts spendula:auth:start rather than
        // re-hitting the same callback URL with stale state.
        $rowsAffected = AuthRequest::query()
            ->whereKey($authRequest->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        if ($rowsAffected === 0) {
            throw new InvalidCallbackStateException(
                "Callback state was already consumed by a concurrent request (state={$state})."
            );
        }

        $authRequest->refresh();

        $session = $this->client->exchangeCode($code);

        if (! isset($session['session_id']) || ! is_string($session['session_id'])) {
            // The auth_request is already consumed and the EB code is one-shot,
            // so we can't write this through the normal bank_connections.raw_session_response
            // path (the row needs a session_id). Persist the full envelope to the
            // log at error level instead, keyed by auth_request_id, so the operator
            // can still recover the data without restarting the consent flow blind.
            Log::error('Enable Banking session response missing session_id; raw payload preserved here for forensic recovery.', [
                'event' => 'callback.malformed_session_id',
                'auth_request_id' => $authRequest->id,
                'bank_slug' => $authRequest->bank_slug,
                'raw_session_response' => $session,
            ]);

            throw new RuntimeException('Enable Banking session response missing session_id.');
        }

        if (! isset($session['accounts']) || ! is_array($session['accounts'])) {
            // A "successful" 200 with no accounts list still creates an active
            // bank_connection (and renders the success page), but spendula:sync
            // would then become a permanent no-op for this bank. Reject early.
            Log::error('Enable Banking session response omitted the accounts array.', [
                'event' => 'callback.missing_accounts',
                'auth_request_id' => $authRequest->id,
                'bank_slug' => $authRequest->bank_slug,
                'raw_session_response' => $session,
            ]);

            throw new RuntimeException('Enable Banking session response omitted the accounts array.');
        }

        // Tolerant valid_until parse: a malformed string would otherwise throw
        // before the raw_session_response insert below, losing the one-shot EB
        // envelope. Fall back to a 90-day default and log so the operator can
        // still inspect the raw payload after the fact.
        try {
            $validUntil = Carbon::parse((string) ($session['access']['valid_until'] ?? Carbon::now()->addDays(90)->toIso8601String()));
        } catch (\Exception $e) {
            Log::warning('Enable Banking returned an unparsable valid_until; defaulting to +90 days.', [
                'event' => 'callback.valid_until_parse_error',
                'reason' => $e->getMessage(),
            ]);
            $validUntil = Carbon::now()->addDays(90);
        }

        // Persist the connection AND upsert every account in a single transaction.
        // Silently skipping a malformed account would let the new connection
        // supersede the prior one without carrying over a working account on
        // re-auth, leaving sync as a permanent no-op for that bank_account. We
        // therefore fail loudly: any account-level RuntimeException rolls back
        // the supersession, the new connection insert, and the partial account
        // upserts together. The auth_request is already consumed, so the
        // operator must trigger a fresh auth — but no working account is lost
        // to a quiet log line.
        // accounts shape was validated above; keep the local for upsert iteration.
        /** @var array<int, array<string, mixed>> $accounts */
        $accounts = $session['accounts'];

        /** @var array{connection: BankConnection, accounts: array<int, BankAccount>} $result */
        $result = DB::transaction(function () use ($authRequest, $session, $validUntil, $accounts): array {
            // Lock the parent bank row to serialize concurrent callbacks for the
            // same bank_slug. Without this, two near-simultaneous reauths can
            // both flip the existing active row to superseded and then race to
            // insert their new active rows — the partial unique index on
            // status='active' will reject the loser as a 500. Holding this lock
            // for the duration of the transaction makes the supersede+insert
            // sequence atomic per bank.
            Bank::query()
                ->whereKey($authRequest->bank_slug)
                ->lockForUpdate()
                ->first();

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

            // Step 4: upsert every account inside the same transaction. A
            // RuntimeException here propagates out of the closure and rolls
            // back Steps 1–3 along with the partial accounts.
            $discovered = [];
            foreach ($accounts as $account) {
                try {
                    $discovered[] = $this->upsertAccount($authRequest->bank_slug, $connection, $account);
                } catch (RuntimeException $e) {
                    Log::error('Enable Banking account upsert failed; rolling back callback.', [
                        'event' => 'callback.account_failed',
                        'bank_slug' => $authRequest->bank_slug,
                        'connection_id' => $connection->id,
                        'reason' => $e->getMessage(),
                        'raw_session_response' => $session,
                    ]);
                    throw $e;
                }
            }

            return [
                'connection' => $connection,
                'accounts' => $discovered,
            ];
        });

        return [
            'connection' => $result['connection']->refresh(),
            'accounts' => $result['accounts'],
        ];
    }

    /** @param  array<string, mixed>  $account */
    private function upsertAccount(string $bankSlug, BankConnection $connection, array $account): BankAccount
    {
        $uid = isset($account['uid']) && is_string($account['uid']) ? $account['uid'] : '';
        if ($uid === '') {
            // Without a uid every later /accounts/{uid}/transactions call would
            // hit /accounts//transactions and silently break sync. Reject early,
            // same as we already do for missing identification_hash.
            throw new RuntimeException('Enable Banking account has no uid — cannot proceed (SPEC §10.1).');
        }

        $primaryHash = (string) ($account['identification_hash'] ?? '');
        /** @var array<int, string> $allHashes */
        $allHashes = is_array($account['identification_hashes'] ?? null)
            ? array_values(array_filter($account['identification_hashes'], 'is_string'))
            : [];

        // SPEC §10.1: identification_hash is the stable identity key; if the
        // payload is missing the *primary* hash, syncIdentifiers would write
        // every secondary with is_primary=false, leaving accounts with no
        // primary identifier (and stripping any prior primary on reauth).
        // Skip these accounts entirely instead of corrupting identifier state.
        if ($primaryHash === '') {
            throw new RuntimeException('Enable Banking account has no primary identification_hash — cannot proceed (SPEC §10.1).');
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
                'enable_banking_uid' => $uid,
            ]
        );

        // last_continuation_key is session-scoped (it's a cursor issued for the
        // EB session UID we just superseded). Carrying it across reauth would
        // make the first sync resume against a session that no longer exists,
        // so explicitly clear it here on every link, fresh or relink.
        BankAccountSyncState::query()->updateOrCreate(
            ['bank_account_id' => $bankAccount->id],
            ['last_continuation_key' => null],
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
