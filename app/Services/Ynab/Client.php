<?php

namespace App\Services\Ynab;

use App\Services\Ynab\Exceptions\YnabAuthException;
use App\Services\Ynab\Exceptions\YnabRateLimitException;
use App\Services\Ynab\Exceptions\YnabServerException;
use App\Services\Ynab\Exceptions\YnabValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * YNAB API wrapper. Uses /plans/{plan_id}/… throughout — YNAB renamed
 * budgets to plans on 2026-03-05; the old /budgets/ path still works for
 * back-compat but is undocumented (SPEC §4.13 "Note on YNAB API naming").
 *
 * Auto-unwraps the {data: …} envelope that every YNAB response wraps its
 * payload in — saves every caller from repeating that unwrap.
 *
 * Failure handling per SPEC §10.2:
 *   - 401: hard fail (YnabAuthException), operator fixes .env
 *   - 429: back off 60s, one retry; still failing → YnabRateLimitException
 *   - 4xx other: YnabValidationException (callers log + leave transactions approved)
 *   - 5xx: two retries with exponential backoff; still failing → YnabServerException
 *   - Network timeout after request sent: treat as retriable
 */
class Client
{
    private const array RETRY_DELAYS_MS_5XX = [2_000, 8_000];

    private const int RETRY_DELAY_MS_429 = 60_000;

    public function __construct(
        private readonly string $accessToken,
        private readonly string $planId,
        private readonly string $baseUrl,
    ) {}

    /** @return array<string, mixed> */
    public function user(): array
    {
        return $this->requestJson('GET', '/user');
    }

    /** @return array<string, mixed> */
    public function accounts(): array
    {
        return $this->requestJson('GET', "/plans/{$this->planId}/accounts");
    }

    /**
     * Bulk-create transactions under /plans/{plan_id}/transactions.
     * The YNAB response body contains both `transactions` (created) and
     * `duplicate_import_ids` (already existed on the server side).
     *
     * @param  list<array<string, mixed>>  $transactions
     * @return array<string, mixed>
     */
    public function createTransactions(array $transactions): array
    {
        return $this->requestJson('POST', "/plans/{$this->planId}/transactions", body: ['transactions' => $transactions]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $body = []): array
    {
        $attempts = 1 + count(self::RETRY_DELAYS_MS_5XX);
        $response = null;
        $lastConnectionError = null;
        $rateLimitedOnce = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->singleRequest($method, $path, $body);
                $lastConnectionError = null;

                if ($response->status() === 429 && ! $rateLimitedOnce) {
                    // SPEC §10.2: back off 60s, retry once.
                    $rateLimitedOnce = true;
                    Sleep::for(self::RETRY_DELAY_MS_429)->milliseconds();

                    continue;
                }

                if ($response->status() < 500) {
                    break;
                }
            } catch (ConnectionException $e) {
                $lastConnectionError = $e;
                $response = null;
            }

            if ($attempt < $attempts) {
                Sleep::for(self::RETRY_DELAYS_MS_5XX[$attempt - 1])->milliseconds();
            }
        }

        if ($response === null) {
            throw new YnabServerException(
                "YNAB transport failure on {$method} {$path}: "
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
            throw new YnabAuthException(
                'YNAB rejected the access token (401). Check SPENDULA_YNAB_ACCESS_TOKEN.',
                $status,
                $this->safeJson($response),
            );
        }
        if ($status === 429) {
            throw new YnabRateLimitException(
                "YNAB rate limit on {$method} {$path} after retry.",
                $status,
                $this->safeJson($response),
            );
        }
        if ($status >= 500) {
            throw new YnabServerException(
                "YNAB returned HTTP {$status} on {$method} {$path} after retries.",
                $status,
                $this->safeJson($response),
            );
        }
        if ($status >= 400) {
            throw new YnabValidationException(
                "YNAB returned HTTP {$status} on {$method} {$path}.",
                $status,
                $this->safeJson($response),
            );
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return [];
        }

        // Auto-unwrap the {data: …} envelope so callers never repeat it.
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }

    /** @param  array<string, mixed>  $body */
    private function singleRequest(string $method, string $path, array $body): Response
    {
        $pending = Http::withToken($this->accessToken)
            ->acceptJson()
            ->asJson();

        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');

        return match (strtoupper($method)) {
            'GET' => $pending->get($url),
            'POST' => $pending->post($url, $body),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
        };
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
            (string) config('spendula.ynab.access_token'),
            (string) config('spendula.ynab.plan_id'),
            (string) config('spendula.ynab.base_url'),
        );
    }
}
