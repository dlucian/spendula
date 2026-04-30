<?php

namespace Tests\Feature\Services\EnableBanking;

use App\Services\EnableBanking\Client;
use App\Services\EnableBanking\Exceptions\EnableBankingAuthException;
use App\Services\EnableBanking\Exceptions\EnableBankingRateLimitException;
use App\Services\EnableBanking\Exceptions\EnableBankingRevokedException;
use App\Services\EnableBanking\Exceptions\EnableBankingServerException;
use App\Services\EnableBanking\Jwt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ClientAccountBalancesTest extends TestCase
{
    private const string BASE_URL = 'https://api.enablebanking.test';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_returns_decoded_balances_for_correct_path(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/uid-1/balances' => Http::response([
                'balances' => [
                    [
                        'balance_type' => 'interim_available',
                        'balance_amount' => ['amount' => '1234.56', 'currency' => 'RON'],
                        'credit_debit_indicator' => 'CRDT',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->client()->accountBalances('uid-1');

        $this->assertSame('interim_available', $response['balances'][0]['balance_type']);
        $this->assertSame('1234.56', $response['balances'][0]['balance_amount']['amount']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === self::BASE_URL.'/accounts/uid-1/balances'
                && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Bearer ');
        });
    }

    public function test_401_raises_auth_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/uid-1/balances' => Http::response(['error' => 'invalid_token'], 401),
        ]);

        $this->expectException(EnableBankingAuthException::class);
        $this->client()->accountBalances('uid-1');
    }

    public function test_403_raises_revoked_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/uid-1/balances' => Http::response(['error' => 'consent_revoked'], 403),
        ]);

        $this->expectException(EnableBankingRevokedException::class);
        $this->client()->accountBalances('uid-1');
    }

    public function test_429_raises_rate_limit_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/uid-1/balances' => Http::response(['error' => 'rate_limit'], 429),
        ]);

        $this->expectException(EnableBankingRateLimitException::class);
        $this->client()->accountBalances('uid-1');
    }

    public function test_5xx_retries_then_raises_server_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/accounts/uid-1/balances' => Http::response(['error' => 'boom'], 503),
        ]);

        try {
            $this->client()->accountBalances('uid-1');
            $this->fail('Expected EnableBankingServerException was not thrown.');
        } catch (EnableBankingServerException) {
            // expected
        }

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    private function client(): Client
    {
        return new Client(new StubJwtForBalances, self::BASE_URL);
    }
}

class StubJwtForBalances extends Jwt
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
