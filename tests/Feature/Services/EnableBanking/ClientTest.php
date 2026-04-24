<?php

namespace Tests\Feature\Services\EnableBanking;

use App\Services\EnableBanking\Client;
use App\Services\EnableBanking\Exceptions\EnableBankingAuthException;
use App\Services\EnableBanking\Exceptions\EnableBankingHttpException;
use App\Services\EnableBanking\Exceptions\EnableBankingRateLimitException;
use App\Services\EnableBanking\Exceptions\EnableBankingRevokedException;
use App\Services\EnableBanking\Exceptions\EnableBankingServerException;
use App\Services\EnableBanking\Jwt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ClientTest extends TestCase
{
    private const string BASE_URL = 'https://api.enablebanking.test';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_application_returns_decoded_json(): void
    {
        Http::fake([
            self::BASE_URL.'/application' => Http::response(['name' => 'Spendula', 'redirect_urls' => []], 200),
        ]);

        $response = $this->client()->application();

        $this->assertSame('Spendula', $response['name']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === self::BASE_URL.'/application'
                && str_starts_with((string) $request->header('Authorization')[0], 'Bearer ');
        });
    }

    public function test_401_raises_auth_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/application' => Http::response(['error' => 'invalid_token'], 401),
        ]);

        $this->expectException(EnableBankingAuthException::class);
        $this->client()->application();
    }

    public function test_403_raises_revoked_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/abc/transactions*' => Http::response(['error' => 'consent_revoked'], 403),
        ]);

        $this->expectException(EnableBankingRevokedException::class);
        $this->client()->accountTransactions('abc');
    }

    public function test_429_raises_rate_limit_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/abc/transactions*' => Http::response(['error' => 'rate_limit'], 429),
        ]);

        $this->expectException(EnableBankingRateLimitException::class);
        $this->client()->accountTransactions('abc');
    }

    public function test_500_retries_then_raises_server_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/application' => Http::response(['error' => 'boom'], 500),
        ]);

        try {
            $this->client()->application();
            $this->fail('Expected EnableBankingServerException was not thrown.');
        } catch (EnableBankingServerException) {
            // expected
        }

        // One original attempt + two retries = three total.
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_5xx_recovers_on_retry(): void
    {
        Http::fakeSequence()
            ->push(['temporary' => 'glitch'], 502)
            ->push(['aspsps' => []], 200);

        $response = $this->client()->aspsps();

        $this->assertSame(['aspsps' => []], $response);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_4xx_non_classified_raises_http_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/auth' => Http::response(['error' => 'bad_request'], 400),
        ]);

        $this->expectException(EnableBankingHttpException::class);
        $this->client()->startAuth(['foo' => 'bar']);
    }

    public function test_account_transactions_forwards_query_params(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/uid-1/transactions*' => Http::response(['transactions' => []], 200),
        ]);

        $this->client()->accountTransactions('uid-1', dateFrom: '2026-04-01', continuationKey: 'k1');

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'date_from=2026-04-01')
                && str_contains($request->url(), 'continuation_key=k1');
        });
    }

    private function client(): Client
    {
        return new Client(new StubJwt, self::BASE_URL);
    }
}

class StubJwt extends Jwt
{
    public function __construct()
    {
        parent::__construct('stub-app', 'stub-key');
    }

    public function sign(int $ttlSeconds = 3600): string
    {
        return 'stub-token';
    }
}
