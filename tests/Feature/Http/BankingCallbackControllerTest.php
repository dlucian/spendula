<?php

namespace Tests\Feature\Http;

use App\Enums\BankConnectionStatus;
use App\Models\AuthRequest;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankAccountIdentifier;
use App\Models\BankAccountSession;
use App\Models\BankAccountSyncState;
use App\Models\BankConnection;
use App\Services\EnableBanking\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BankingCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('spendula.enable_banking.base_url', 'https://api.enablebanking.test');
        $this->app->bind(Jwt::class, fn () => new class('app', 'key') extends Jwt
        {
            public function sign(int $ttlSeconds = 3600): string
            {
                return 'stub';
            }
        });
        $this->seedMockBank();
    }

    public function test_first_time_auth_persists_connection_accounts_identifiers_and_sync_state(): void
    {
        $auth = $this->openAuthRequest();

        Http::fake([
            'https://api.enablebanking.test/sessions' => Http::response($this->sessionPayload(), 200),
        ]);

        $response = $this->get("/banking/callback?code=fake-code&state={$auth->state}");

        $response->assertStatus(200);
        $response->assertSeeInOrder(['<strong>2</strong>', 'accounts discovered'], false);

        $this->assertSame(1, BankConnection::query()->count());
        $connection = BankConnection::query()->sole();
        $this->assertSame(BankConnectionStatus::Active, $connection->status);
        $this->assertSame('session-xyz', $connection->enable_banking_session_id);

        $accounts = BankAccount::query()->get();
        $this->assertCount(2, $accounts);
        $this->assertEqualsCanonicalizing(['EUR', 'RON'], $accounts->pluck('currency')->sort()->values()->all());

        $eurAccount = $accounts->firstWhere('currency', 'EUR');
        $this->assertTrue($eurAccount->is_base_currency);
        $this->assertNull($eurAccount->ynab_account_id);
        $this->assertTrue($eurAccount->active);

        $ronAccount = $accounts->firstWhere('currency', 'RON');
        $this->assertFalse($ronAccount->is_base_currency);

        // Identifiers: both hashes per account, one primary.
        $this->assertSame(4, BankAccountIdentifier::query()->count());
        foreach ($accounts as $account) {
            $primary = BankAccountIdentifier::query()
                ->where('bank_account_id', $account->id)
                ->where('is_primary', true)
                ->count();
            $this->assertSame(1, $primary, 'Exactly one primary identifier per account.');
        }

        // Sessions + sync state.
        $this->assertSame(2, BankAccountSession::query()->count());
        $this->assertSame(2, BankAccountSyncState::query()->count());

        // auth_request consumed.
        $auth->refresh();
        $this->assertNotNull($auth->consumed_at);
    }

    public function test_second_authorization_supersedes_the_first_and_reuses_bank_accounts(): void
    {
        $firstAuth = $this->openAuthRequest();
        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($this->sessionPayload('session-1st'), 200)]);
        $this->get("/banking/callback?code=c1&state={$firstAuth->state}")->assertStatus(200);

        $firstConn = BankConnection::query()->sole();
        $this->assertSame(BankConnectionStatus::Active, $firstConn->status);
        $initialAccountIds = BankAccount::query()->pluck('id')->sort()->values()->all();

        // Second auth with same hashes (re-consent scenario).
        $secondAuth = $this->openAuthRequest();
        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($this->sessionPayload('session-2nd'), 200)]);
        $this->get("/banking/callback?code=c2&state={$secondAuth->state}")->assertStatus(200);

        $this->assertSame(2, BankConnection::query()->count());
        $this->assertSame(2, BankAccount::query()->count(), 'Accounts must be matched via identification_hash, not re-inserted.');

        $reusedIds = BankAccount::query()->pluck('id')->sort()->values()->all();
        $this->assertSame($initialAccountIds, $reusedIds);

        $firstConn->refresh();
        $this->assertSame(BankConnectionStatus::Superseded, $firstConn->status);

        $secondConn = BankConnection::query()
            ->where('id', '!=', $firstConn->id)
            ->sole();
        $this->assertSame(BankConnectionStatus::Active, $secondConn->status);
        $this->assertSame($secondConn->id, $firstConn->superseded_by_id);

        // Identifiers: no duplicates introduced.
        $this->assertSame(4, BankAccountIdentifier::query()->count());

        // Sessions rows: one per (connection, account), so 2 * 2 = 4.
        $this->assertSame(4, BankAccountSession::query()->count());
    }

    public function test_invalid_state_returns_400(): void
    {
        $response = $this->get('/banking/callback?code=whatever&state=not-an-open-state');

        $response->assertStatus(400);
        $response->assertSee('expired or has already been consumed', false);
    }

    public function test_expired_state_returns_400(): void
    {
        $auth = $this->openAuthRequest(expired: true);

        $response = $this->get("/banking/callback?code=c&state={$auth->state}");
        $response->assertStatus(400);
    }

    public function test_eb_rejection_returns_502(): void
    {
        $auth = $this->openAuthRequest();

        Http::fake([
            'https://api.enablebanking.test/sessions' => Http::response(['error' => 'bad_code'], 400),
        ]);

        $response = $this->get("/banking/callback?code=c&state={$auth->state}");
        $response->assertStatus(502);

        // auth_request not consumed (exception before commit, and we only mark consumed inside the transaction).
        $auth->refresh();
        $this->assertNull($auth->consumed_at);
        $this->assertSame(0, BankConnection::query()->count());
    }

    public function test_error_query_parameter_returns_400(): void
    {
        $response = $this->get('/banking/callback?error=server_error&state=whatever');
        $response->assertStatus(400);
        $response->assertSee('error=server_error', false);
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

    private function openAuthRequest(bool $expired = false): AuthRequest
    {
        return AuthRequest::query()->create([
            'state' => 'state-'.uniqid(),
            'bank_slug' => 'mock',
            'expires_at' => $expired ? Carbon::now()->subHour() : Carbon::now()->addMinutes(15),
        ]);
    }

    /** @return array<string, mixed> */
    private function sessionPayload(string $sessionId = 'session-xyz'): array
    {
        return [
            'session_id' => $sessionId,
            'accounts' => [
                [
                    'uid' => 'uid-eur',
                    'name' => 'Onni Nieminen',
                    'currency' => 'EUR',
                    'cash_account_type' => 'CACC',
                    'identification_hash' => 'HASH-EUR-PRIMARY',
                    'identification_hashes' => ['HASH-EUR-PRIMARY', 'HASH-EUR-ALT'],
                ],
                [
                    'uid' => 'uid-ron',
                    'name' => 'Akseli Virtanen',
                    'currency' => 'RON',
                    'cash_account_type' => 'CACC',
                    'identification_hash' => 'HASH-RON-PRIMARY',
                    'identification_hashes' => ['HASH-RON-PRIMARY', 'HASH-RON-ALT'],
                ],
            ],
            'aspsp' => ['name' => 'Mock ASPSP', 'country' => 'FI'],
            'psu_type' => 'personal',
            'access' => [
                'valid_until' => Carbon::now()->addDays(90)->toIso8601ZuluString(),
            ],
        ];
    }
}
