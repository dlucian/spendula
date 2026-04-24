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

    /** @return array<string, mixed> */
    public function application(): array
    {
        return $this->requestJson('GET', '/application');
    }

    /** @return array<string, mixed> */
    public function aspsps(): array
    {
        return $this->requestJson('GET', '/aspsps');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function startAuth(array $body): array
    {
        return $this->requestJson('POST', '/auth', body: $body);
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code): array
    {
        return $this->requestJson('POST', '/sessions', body: ['code' => $code]);
    }

    /** @return array<string, mixed> */
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
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $query = [], array $body = []): array
    {
        $attempts = 1 + count(self::RETRY_DELAYS_MS);
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
