<?php

namespace Tests\Feature\Commands\Spendula;

use App\Models\AuthRequest;
use App\Models\Bank;
use App\Services\EnableBanking\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthStartCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('spendula.enable_banking.base_url', 'https://api.enablebanking.test');
        config()->set('spendula.callback_url', 'http://localhost:8000/banking/callback');
    }

    public function test_prints_the_consent_url_and_persists_an_auth_request(): void
    {
        $this->seedMockBank();

        Http::fake([
            'https://api.enablebanking.test/auth' => Http::response(['url' => 'https://tilisy-sandbox.example/ais/start?sessionid=xxx'], 200),
        ]);

        $this->useFakeJwt();

        $this->artisan('spendula:auth:start', ['bank_slug' => 'mock'])
            ->expectsOutputToContain('https://tilisy-sandbox.example/ais/start?sessionid=xxx')
            ->expectsOutputToContain('expire')
            ->assertSuccessful();

        $auth = AuthRequest::query()->firstOrFail();
        $this->assertSame('mock', $auth->bank_slug);
        $this->assertNull($auth->consumed_at);
        $this->assertTrue($auth->expires_at->isFuture());

        Http::assertSent(function (Request $req) use ($auth): bool {
            $body = $req->data();

            return $req->url() === 'https://api.enablebanking.test/auth'
                && $body['state'] === $auth->state
                && $body['aspsp']['name'] === 'Mock ASPSP'
                && $body['aspsp']['country'] === 'FI'
                && $body['psu_type'] === 'personal'
                && $body['redirect_url'] === 'http://localhost:8000/banking/callback'
                && isset($body['access']['valid_until']);
        });
    }

    public function test_fails_when_bank_is_not_active(): void
    {
        $this->useFakeJwt();

        $this->artisan('spendula:auth:start', ['bank_slug' => 'nonexistent'])
            ->expectsOutputToContain("No active bank with slug 'nonexistent'")
            ->assertFailed();

        $this->assertSame(0, AuthRequest::query()->count());
    }

    public function test_fails_when_eb_rejects_the_auth_call(): void
    {
        $this->seedMockBank();
        $this->useFakeJwt();

        Http::fake([
            'https://api.enablebanking.test/auth' => Http::response(['error' => 'invalid_token'], 401),
        ]);

        $this->artisan('spendula:auth:start', ['bank_slug' => 'mock'])
            ->expectsOutputToContain('Enable Banking rejected')
            ->assertFailed();

        $this->assertSame(1, AuthRequest::query()->count(), 'auth_request should still be persisted for traceability.');
        $this->assertNull(AuthRequest::query()->first()?->consumed_at);
    }

    private function seedMockBank(): void
    {
        Bank::query()->create([
            'slug' => 'mock',
            'display_name' => 'Mock ASPSP',
            'aspsp_name' => 'Mock ASPSP',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);
    }

    private function useFakeJwt(): void
    {
        // Bypass real key-file reads inside the container; Http::fake() doesn't care
        // about the header contents, only that a Bearer token is sent.
        $this->app->bind(Jwt::class, fn () => new class('app-id', 'key') extends Jwt
        {
            public function sign(int $ttlSeconds = 3600): string
            {
                return 'fake-token';
            }
        });
    }
}
