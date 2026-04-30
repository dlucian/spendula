<?php

namespace App\Services\EnableBanking;

use App\Services\EnableBanking\Exceptions\EnableBankingAuthException;
use App\Services\EnableBanking\Exceptions\EnableBankingHttpException;
use App\Services\EnableBanking\Exceptions\EnableBankingRateLimitException;
use App\Services\EnableBanking\Exceptions\EnableBankingRevokedException;
use App\Services\EnableBanking\Exceptions\EnableBankingServerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * HTTP wrapper over the Enable Banking API. All PSD2 traffic flows through
 * here, so this is the single place that implements SPEC §10.1's error
 * ladder (5xx retry, 401/403/429 typed exceptions, everything else raises).
 *
 * The class is intentionally thin — callers own business logic like
 * "persist continuation_key and abort on 429". The client only classifies.
 */
class Client
{
    /** Retry delays per SPEC §10.1 (milliseconds), applied to 5xx and transport failures. */
    private const array RETRY_DELAYS_MS = [2_000, 8_000];

    public function __construct(
        private readonly Jwt $jwt,
        private readonly string $baseUrl,
    ) {}

    /**
     * Sign a token and discard it. Lets callers surface JWT/config failures
     * (missing app id, unreadable private key) before they perform irreversible
     * local work — e.g. CallbackHandler marking the auth_request consumed.
     */
    public function preflight(): void
    {
        $this->jwt->sign();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exceptions\EnableBankingException
     * @throws RuntimeException for local JWT/config failures (missing app id or unreadable private key).
     */
    public function application(): array
    {
        return $this->requestJson('GET', '/application');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exceptions\EnableBankingException
     * @throws RuntimeException for local JWT/config failures (missing app id or unreadable private key).
     */
    public function aspsps(): array
    {
        return $this->requestJson('GET', '/aspsps');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws Exceptions\EnableBankingException
     * @throws RuntimeException for local JWT/config failures (missing app id or unreadable private key).
     */
    public function startAuth(array $body): array
    {
        return $this->requestJson('POST', '/auth', body: $body);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exceptions\EnableBankingException
     * @throws RuntimeException for local JWT/config failures (missing app id or unreadable private key).
     */
    public function exchangeCode(string $code): array
    {
        return $this->requestJson('POST', '/sessions', body: ['code' => $code]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exceptions\EnableBankingException
     * @throws RuntimeException for local JWT/config failures (missing app id or unreadable private key).
     */
    public function accountTransactions(
        string $uid,
        ?string $dateFrom = null,
        ?string $continuationKey = null,
    ): array {
        $query = array_filter([
            'date_from' => $dateFrom,
            'continuation_key' => $continuationKey,
        ], fn ($v) => $v !== null);

        return $this->requestJson('GET', "/accounts/{$uid}/transactions", query: $query);
    }

    /**
     * Fetch the live balances for the account identified by Enable Banking
     * UID. Used by the tracking-snapshot path (SPEC §5.3) which converts
     * the picked balance to EUR and reconciles against YNAB.
     *
     * Success: returns the parsed JSON envelope; callers index `balances[]`
     *   to pick the appropriate `balance_type` (typically `interim_available`).
     *   Balance amount strings flow through bcmath at the caller per
     *   CLAUDE.md money rules.
     *
     * Failure: same exception ladder as {@see accountTransactions()} — 401
     *   → EnableBankingAuthException, 403 → EnableBankingRevokedException,
     *   429 → EnableBankingRateLimitException, 5xx →
     *   EnableBankingServerException after retries, other 4xx →
     *   EnableBankingHttpException.
     *
     * Side effects: HTTP GET to `/accounts/{uid}/balances`. Idempotent;
     *   safe to retry on transport failure (see {@see requestJson()}).
     *
     * @return array<string, mixed>
     *
     * @throws Exceptions\EnableBankingException
     * @throws RuntimeException for local JWT/config failures (missing app id or unreadable private key).
     */
    public function accountBalances(string $uid): array
    {
        return $this->requestJson('GET', "/accounts/{$uid}/balances");
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $query = [], array $body = []): array
    {
        // Only GETs are idempotent enough to safely retry on transport or 5xx
        // failures. POSTs (e.g. /sessions, /auth) create one-shot server state;
        // a retry after a request that may have already reached EB would
        // double-spend a callback code or mint a duplicate auth_request.
        $isIdempotent = strtoupper($method) === 'GET';
        $attempts = $isIdempotent ? 1 + count(self::RETRY_DELAYS_MS) : 1;
        $response = null;
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->singleRequest($method, $path, $query, $body);
                $lastConnectionError = null;

                if ($response->status() < 500) {
                    break;
                }
            } catch (ConnectionException $e) {
                $lastConnectionError = $e;
                $response = null;
            }

            if ($attempt < $attempts) {
                Sleep::for(self::RETRY_DELAYS_MS[$attempt - 1])->milliseconds();
            }
        }

        if ($response === null) {
            throw new EnableBankingServerException(
                "Enable Banking transport failure on {$method} {$path}: "
                .($lastConnectionError?->getMessage() ?? 'unknown error')
            );
        }

        return $this->classify($method, $path, $response);
    }

    /** @return array<string, mixed> */
    private function classify(string $method, string $path, Response $response): array
    {
        $status = $response->status();

        if ($status === 401) {
            throw new EnableBankingAuthException(
                'Enable Banking rejected JWT (401). Check SPENDULA_ENABLE_BANKING_APP_ID and private key.',
                $status,
                $this->safeJson($response),
            );
        }

        if ($status === 403) {
            throw new EnableBankingRevokedException(
                "Enable Banking returned 403 (consent revoked or forbidden) for {$method} {$path}.",
                $status,
                $this->safeJson($response),
            );
        }

        if ($status === 429) {
            throw new EnableBankingRateLimitException(
                "Enable Banking rate limit hit on {$method} {$path}.",
                $status,
                $this->safeJson($response),
            );
        }

        if ($status >= 500) {
            throw new EnableBankingServerException(
                "Enable Banking returned HTTP {$status} on {$method} {$path} after retries.",
                $status,
                $this->safeJson($response),
            );
        }

        if ($status >= 400) {
            throw new EnableBankingHttpException(
                "Enable Banking returned HTTP {$status} on {$method} {$path}.",
                $status,
                $this->safeJson($response),
            );
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    private function singleRequest(string $method, string $path, array $query, array $body): Response
    {
        $pending = Http::withToken($this->jwt->sign())
            ->acceptJson()
            ->asJson();

        $url = $this->url($path);

        return match (strtoupper($method)) {
            'GET' => $pending->get($url, $query),
            'POST' => $pending->post($url, $body),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
        };
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /** @return array<string, mixed>|null */
    private function safeJson(Response $response): ?array
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    public static function fromConfig(): self
    {
        return new self(
            Jwt::fromConfig(),
            (string) config('spendula.enable_banking.base_url'),
        );
    }
}
