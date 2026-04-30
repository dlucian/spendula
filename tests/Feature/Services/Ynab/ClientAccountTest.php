<?php

namespace Tests\Feature\Services\Ynab;

use App\Services\Ynab\Client;
use App\Services\Ynab\Exceptions\YnabAuthException;
use App\Services\Ynab\Exceptions\YnabRateLimitException;
use App\Services\Ynab\Exceptions\YnabServerException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ClientAccountTest extends TestCase
{
    private const string BASE_URL = 'https://api.ynab.test/v1';

    private const string PLAN_ID = 'plan-under-test';

    private const string ACCOUNT_ID = '79f0ce5c-5cff-40dd-8560-363caf59b878';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_account_issues_correct_path_and_unwraps_data(): void
    {
        Http::fake([
            self::BASE_URL.'/plans/'.self::PLAN_ID.'/accounts/'.self::ACCOUNT_ID => Http::response([
                'data' => [
                    'account' => [
                        'id' => self::ACCOUNT_ID,
                        'name' => 'Tracking RON',
                        'balance' => 240_000,
                        'on_budget' => false,
                    ],
                ],
            ], 200),
        ]);

        $payload = $this->client()->account(self::ACCOUNT_ID);

        // YnabClient auto-unwraps {data: …}.
        $this->assertSame(self::ACCOUNT_ID, $payload['account']['id']);
        $this->assertSame(240_000, $payload['account']['balance']);
        $this->assertFalse($payload['account']['on_budget']);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === self::BASE_URL.'/plans/'.self::PLAN_ID.'/accounts/'.self::ACCOUNT_ID
                && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Bearer ');
        });
    }

    public function test_401_raises_auth_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/plans/'.self::PLAN_ID.'/accounts/'.self::ACCOUNT_ID => Http::response(['error' => 'invalid_token'], 401),
        ]);

        $this->expectException(YnabAuthException::class);
        $this->client()->account(self::ACCOUNT_ID);
    }

    public function test_429_raises_rate_limit_after_one_retry(): void
    {
        Http::fake([
            self::BASE_URL.'/plans/'.self::PLAN_ID.'/accounts/'.self::ACCOUNT_ID => Http::response(['error' => 'rate_limit'], 429),
        ]);

        try {
            $this->client()->account(self::ACCOUNT_ID);
            $this->fail('Expected YnabRateLimitException was not thrown.');
        } catch (YnabRateLimitException) {
            // expected
        }

        Http::assertSentCount(2);
    }

    public function test_5xx_retries_then_raises_server_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/plans/'.self::PLAN_ID.'/accounts/'.self::ACCOUNT_ID => Http::response(['error' => 'boom'], 503),
        ]);

        try {
            $this->client()->account(self::ACCOUNT_ID);
            $this->fail('Expected YnabServerException was not thrown.');
        } catch (YnabServerException) {
            // expected
        }

        Http::assertSentCount(3);
    }

    private function client(): Client
    {
        return new Client('test-token', self::PLAN_ID, self::BASE_URL);
    }
}
