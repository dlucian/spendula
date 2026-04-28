<?php

namespace Tests\Feature\Http;

use App\Enums\BankConnectionStatus;
use App\Enums\PsuType;
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

    public function test_multi_account_same_holder_does_not_collapse_into_one_row(): void
    {
        // Regression for GH issue #3: when EB returns >1 account with the same
        // holder name (Revolut LT EUR + RON-via-LT, ING RO with multiple
        // personal accounts, etc.), the identification_hash matcher used to
        // collide on the [aspsp_name, aspsp_country, account.name] hash and
        // collapse both EB accounts into a single bank_account row. Each
        // account in the fixture must produce its own row.
        Bank::query()->create([
            'slug' => 'revolut',
            'display_name' => 'Revolut LT',
            'aspsp_name' => 'Revolut',
            'aspsp_country' => 'LT',
            'psu_type' => PsuType::Personal,
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $auth = AuthRequest::query()->create([
            'state' => 'state-revolut-'.uniqid(),
            'bank_slug' => 'revolut',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $raw = file_get_contents(base_path('tests/fixtures/enablebanking/revolut-multiaccount-same-holder.json'));
        $this->assertNotFalse($raw, 'Fixture file must exist.');
        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        Http::fake([
            'https://api.enablebanking.test/sessions' => Http::response($payload, 200),
        ]);

        $this->get("/banking/callback?code=fake-code&state={$auth->state}")
            ->assertStatus(200);

        // Two distinct bank_accounts — one EUR (LT), one RON (RO).
        $accounts = BankAccount::query()->get();
        $this->assertCount(2, $accounts, 'Multi-account same-holder consent must yield distinct bank_account rows.');
        $this->assertEqualsCanonicalizing(['EUR', 'RON'], $accounts->pluck('currency')->all());

        $eur = $accounts->firstWhere('currency', 'EUR');
        $ron = $accounts->firstWhere('currency', 'RON');
        $this->assertSame('LT890000000000000000', $eur->iban, 'EUR account must keep its own LT IBAN.');
        $this->assertSame('RO99REVO0000000000000000', $ron->iban, 'RON account must keep its own RO IBAN.');

        // Two sessions, one per bank_account, distinct EB UIDs.
        $sessions = BankAccountSession::query()->get();
        $this->assertCount(2, $sessions);
        $this->assertEqualsCanonicalizing(
            ['79f42c33-b1f6-4d7c-b513-0465d11b6e43', '7f4ad85c-f041-40dd-a96f-a4dcde07f0d3'],
            $sessions->pluck('enable_banking_uid')->all(),
            'Both EB account UIDs must be retained, one per bank_account_session row.'
        );
        $this->assertSame(
            $eur->id,
            $sessions->firstWhere('enable_banking_uid', '79f42c33-b1f6-4d7c-b513-0465d11b6e43')->bank_account_id,
            'EUR EB UID must point at the EUR bank_account.'
        );
        $this->assertSame(
            $ron->id,
            $sessions->firstWhere('enable_banking_uid', '7f4ad85c-f041-40dd-a96f-a4dcde07f0d3')->bank_account_id,
            'RON EB UID must point at the RON bank_account.'
        );

        // Each account has exactly one primary identifier.
        foreach ($accounts as $account) {
            $primaryCount = BankAccountIdentifier::query()
                ->where('bank_account_id', $account->id)
                ->where('is_primary', true)
                ->count();
            $this->assertSame(1, $primaryCount, "Account {$account->currency} must have exactly one primary identifier.");
        }
    }

    public function test_re_auth_in_reverse_account_order_keeps_two_rows(): void
    {
        // Regression for GH issue #3: a re-auth where EB returns the sibling
        // accounts in the opposite order from the first link must not throw
        // on the secondary hashes that EB happens to share between siblings
        // (Revolut LT returns the LT IBAN as account.account_id.iban for
        // both EUR and RON accounts, so the iban-only secondary collides).
        Bank::query()->create([
            'slug' => 'revolut',
            'display_name' => 'Revolut LT',
            'aspsp_name' => 'Revolut',
            'aspsp_country' => 'LT',
            'psu_type' => PsuType::Personal,
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $raw = file_get_contents(base_path('tests/fixtures/enablebanking/revolut-multiaccount-same-holder.json'));
        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $firstAuth = AuthRequest::query()->create([
            'state' => 'state-revolut-first-'.uniqid(),
            'bank_slug' => 'revolut',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);
        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($payload, 200)]);
        $this->get("/banking/callback?code=c1&state={$firstAuth->state}")->assertStatus(200);
        $this->assertSame(2, BankAccount::query()->count());
        $initialIds = BankAccount::query()->pluck('id')->sort()->values()->all();

        // Re-auth: same payload but with the accounts list reversed.
        $reversed = $payload;
        $reversed['accounts'] = array_reverse($payload['accounts']);
        $reversed['session_id'] = 'second-revolut-session';

        $secondAuth = AuthRequest::query()->create([
            'state' => 'state-revolut-second-'.uniqid(),
            'bank_slug' => 'revolut',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);
        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($reversed, 200)]);
        $this->get("/banking/callback?code=c2&state={$secondAuth->state}")->assertStatus(200);

        // Same two rows, same currencies, same IBANs.
        $this->assertSame(2, BankAccount::query()->count(), 'Re-auth must reuse rows, not insert new ones.');
        $this->assertSame($initialIds, BankAccount::query()->pluck('id')->sort()->values()->all());
        $this->assertEqualsCanonicalizing(['EUR', 'RON'], BankAccount::query()->pluck('currency')->all());
    }

    public function test_re_consent_with_rotated_primary_reuses_existing_row(): void
    {
        // Codex round 5 P1: EB may rotate identification_hash on a re-consent
        // while keeping the old primary hash in identification_hashes. The
        // primary-only lookup misses, but the secondary-fallback should
        // unambiguously match the existing row and reuse it instead of
        // creating a duplicate (which would strand sync state, history,
        // and the YNAB mapping on the old row).
        $first = $this->sessionPayload('session-1');
        $rotated = $this->sessionPayload('session-2');
        $rotated['accounts'][0]['identification_hash'] = 'HASH-EUR-V2-PRIMARY';
        $rotated['accounts'][0]['identification_hashes'] = ['HASH-EUR-V2-PRIMARY', 'HASH-EUR-PRIMARY', 'HASH-EUR-ALT'];

        Http::fake([
            'https://api.enablebanking.test/sessions' => Http::sequence()
                ->push($first, 200)
                ->push($rotated, 200),
        ]);

        $firstAuth = $this->openAuthRequest();
        $this->get("/banking/callback?code=c1&state={$firstAuth->state}")->assertStatus(200);

        $this->assertSame(2, BankAccount::query()->count());
        $eurId = BankAccount::query()->where('currency', 'EUR')->sole()->id;

        $secondAuth = $this->openAuthRequest();
        $this->get("/banking/callback?code=c2&state={$secondAuth->state}")->assertStatus(200);

        // EUR row reused — no duplicate.
        $this->assertSame(2, BankAccount::query()->count());
        $eur = BankAccount::query()->findOrFail($eurId);
        $this->assertSame('EUR', $eur->currency);

        // The new primary is now persisted as is_primary=true on the
        // existing row; the old primary remains as a secondary.
        $newPrimary = BankAccountIdentifier::query()
            ->where('bank_account_id', $eurId)
            ->where('hash', 'HASH-EUR-V2-PRIMARY')
            ->sole();
        $this->assertTrue($newPrimary->is_primary);

        $oldPrimary = BankAccountIdentifier::query()
            ->where('bank_account_id', $eurId)
            ->where('hash', 'HASH-EUR-PRIMARY')
            ->sole();
        $this->assertFalse($oldPrimary->is_primary);
    }

    public function test_multi_account_re_consent_with_rotated_sibling_primary_reuses_row(): void
    {
        // Codex round 6 P1: in a multi-account bank where siblings share a
        // secondary hash (the Revolut LT shape), if EB later rotates ONE
        // sibling's primary AND the rotated sibling is processed first,
        // the secondary fallback's whereIn matches both rows (the rotated
        // sibling via its old primary + the other sibling via the shared
        // secondary). The disambiguator must prefer the row whose CURRENT
        // primary identifier is in this EB account's hash set.
        Bank::query()->create([
            'slug' => 'revolut',
            'display_name' => 'Revolut LT',
            'aspsp_name' => 'Revolut',
            'aspsp_country' => 'LT',
            'psu_type' => PsuType::Personal,
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $raw = file_get_contents(base_path('tests/fixtures/enablebanking/revolut-multiaccount-same-holder.json'));
        /** @var array<string, mixed> $payload */
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $eurOldPrimary = $payload['accounts'][0]['identification_hash'];
        $ronOldPrimary = $payload['accounts'][1]['identification_hash'];

        // Re-consent payload: RON's primary is rotated (RON_NEW_PRIMARY)
        // and the old RON primary is preserved in identification_hashes.
        // Order is reversed so RON is processed first — that's the
        // failure shape codex flagged.
        $ronRotated = $payload;
        $ronRotated['session_id'] = 'session-2';
        $ronRotated['accounts'] = array_reverse($payload['accounts']);
        $ronRotated['accounts'][0]['identification_hash'] = 'RON-NEW-PRIMARY';
        $ronRotated['accounts'][0]['identification_hashes'] = array_merge(
            ['RON-NEW-PRIMARY'],
            $payload['accounts'][1]['identification_hashes']
        );

        Http::fake([
            'https://api.enablebanking.test/sessions' => Http::sequence()
                ->push($payload, 200)
                ->push($ronRotated, 200),
        ]);

        $firstAuth = AuthRequest::query()->create([
            'state' => 'state-revolut-1-'.uniqid(),
            'bank_slug' => 'revolut',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);
        $this->get("/banking/callback?code=c1&state={$firstAuth->state}")->assertStatus(200);

        $this->assertSame(2, BankAccount::query()->count());
        $eurId = BankAccount::query()->where('currency', 'EUR')->sole()->id;
        $ronId = BankAccount::query()->where('currency', 'RON')->sole()->id;

        $secondAuth = AuthRequest::query()->create([
            'state' => 'state-revolut-2-'.uniqid(),
            'bank_slug' => 'revolut',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);
        $this->get("/banking/callback?code=c2&state={$secondAuth->state}")->assertStatus(200);

        // Same two rows — RON's row reused via the secondary fallback's
        // primary-preference disambiguator, NOT inserted as a duplicate.
        $this->assertSame(2, BankAccount::query()->count(), 'Re-consent must reuse rows even when EB rotates one sibling primary first.');
        $this->assertNotNull(BankAccount::query()->find($eurId));
        $this->assertNotNull(BankAccount::query()->find($ronId));

        // RON's new primary is now persisted on RON's row.
        $newRonPrimary = BankAccountIdentifier::query()
            ->where('bank_account_id', $ronId)
            ->where('hash', 'RON-NEW-PRIMARY')
            ->sole();
        $this->assertTrue($newRonPrimary->is_primary);

        // RON's old primary is now is_primary=false on the same row.
        $oldRonPrimary = BankAccountIdentifier::query()
            ->where('bank_account_id', $ronId)
            ->where('hash', $ronOldPrimary)
            ->sole();
        $this->assertFalse($oldRonPrimary->is_primary);

        // EUR's primary is untouched.
        $eurPrimary = BankAccountIdentifier::query()
            ->where('bank_account_id', $eurId)
            ->where('hash', $eurOldPrimary)
            ->sole();
        $this->assertTrue($eurPrimary->is_primary);
    }

    public function test_callback_rejects_when_match_has_mismatched_currency(): void
    {
        // Codex round 7 P1: if EB ever rotates a shared-secondary hash to
        // be the PRIMARY on a sibling, the lookup will bind that EB account
        // to the OTHER sibling's row. Silently overwriting last_seen_at/iban
        // and sweeping identifiers would route future sync/push work
        // through a misidentified bank_account. The currency-mismatch
        // guard turns that into a fatal callback error (502) so the
        // operator can investigate.
        $auth = $this->openAuthRequest();

        // Pre-seed: EUR row owns hash "SHARED-RON-NEW-PRIMARY" as a
        // secondary. (Models the post-fix Revolut state where one sibling
        // recorded a hash that EB later promotes to be the OTHER
        // sibling's primary.)
        $bank = Bank::query()->findOrFail('mock');
        $eurRow = BankAccount::query()->create([
            'bank_slug' => $bank->slug,
            'iban' => null,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);
        BankAccountIdentifier::query()->create([
            'bank_account_id' => $eurRow->id,
            'hash' => 'SHARED-RON-NEW-PRIMARY',
            'is_primary' => false,
            'first_seen_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);

        // EB returns a single account with that previously-EUR-secondary
        // hash now as its PRIMARY, and currency=RON.
        $payload = [
            'session_id' => 'session-attack',
            'accounts' => [[
                'uid' => 'uid-ron',
                'name' => 'Sibling',
                'currency' => 'RON',
                'cash_account_type' => 'CACC',
                'identification_hash' => 'SHARED-RON-NEW-PRIMARY',
                'identification_hashes' => ['SHARED-RON-NEW-PRIMARY'],
            ]],
            'aspsp' => ['name' => 'Mock ASPSP', 'country' => 'FI'],
            'psu_type' => 'personal',
            'access' => ['valid_until' => Carbon::now()->addDays(90)->toIso8601ZuluString()],
        ];

        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($payload, 200)]);

        // Currency mismatch should surface as 502, NOT silent identity overwrite.
        $this->get("/banking/callback?code=fake&state={$auth->state}")->assertStatus(502);

        // EUR row's currency stays EUR — no rebinding occurred.
        $eurRow->refresh();
        $this->assertSame('EUR', $eurRow->currency);
        $this->assertNull($eurRow->iban);
    }

    public function test_new_sibling_with_only_shared_secondary_hash_is_inserted_not_reused(): void
    {
        // Codex round 8 P1: if a new sibling first arrives in a later
        // consent and its only DB-overlap with the existing row is a
        // non-discriminating secondary (the shared holder-name hash in
        // the Revolut shape), the fallback's count===1 fast-path used
        // to reuse the existing row. The primary-backed disambiguator
        // must reject the match because the existing row's primary is
        // not in this new sibling's hashes.
        $bank = Bank::query()->findOrFail('mock');

        // Pre-existing EUR account with its primary + a shared
        // holder-name secondary.
        $eurRow = BankAccount::query()->create([
            'bank_slug' => $bank->slug,
            'iban' => null,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);
        BankAccountIdentifier::query()->create([
            'bank_account_id' => $eurRow->id,
            'hash' => 'HASH-EUR-PRIMARY-EXISTING',
            'is_primary' => true,
            'first_seen_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);
        BankAccountIdentifier::query()->create([
            'bank_account_id' => $eurRow->id,
            'hash' => 'HASH-HOLDER-NAME-SHARED',
            'is_primary' => false,
            'first_seen_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);

        // Brand-new EUR sibling arriving in the next consent. Shares
        // only the holder-name hash with the existing row; primary is
        // unique to this new sibling.
        $payload = [
            'session_id' => 'session-new-sibling',
            'accounts' => [[
                'uid' => 'uid-new-eur-sibling',
                'name' => 'Same Holder',
                'currency' => 'EUR',
                'cash_account_type' => 'CACC',
                'identification_hash' => 'HASH-NEW-SIBLING-PRIMARY',
                'identification_hashes' => ['HASH-NEW-SIBLING-PRIMARY', 'HASH-HOLDER-NAME-SHARED'],
            ]],
            'aspsp' => ['name' => 'Mock ASPSP', 'country' => 'FI'],
            'psu_type' => 'personal',
            'access' => ['valid_until' => Carbon::now()->addDays(90)->toIso8601ZuluString()],
        ];

        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($payload, 200)]);
        $auth = $this->openAuthRequest();
        $this->get("/banking/callback?code=fake&state={$auth->state}")->assertStatus(200);

        // The new sibling lands as a distinct row, NOT as a reuse of
        // the existing EUR row.
        $this->assertSame(2, BankAccount::query()->count());
        $newRow = BankAccount::query()->where('id', '!=', $eurRow->id)->sole();
        $this->assertSame('EUR', $newRow->currency);

        $newPrimary = BankAccountIdentifier::query()->where('hash', 'HASH-NEW-SIBLING-PRIMARY')->sole();
        $this->assertSame($newRow->id, $newPrimary->bank_account_id);
        $this->assertTrue($newPrimary->is_primary);

        // Existing row's primary identifier untouched.
        $existingPrimary = BankAccountIdentifier::query()->where('hash', 'HASH-EUR-PRIMARY-EXISTING')->sole();
        $this->assertSame($eurRow->id, $existingPrimary->bank_account_id);
        $this->assertTrue($existingPrimary->is_primary);
    }

    public function test_callback_rejects_when_primary_hash_collides_with_sibling_secondary(): void
    {
        // Codex round 9 P1: a same-currency sibling case the round-7
        // currency-mismatch guard does not catch — if EB ever promotes
        // a previously-shared secondary hash to be the OTHER sibling's
        // identification_hash, the lookup must NOT silently rebind us
        // to the row that currently owns the secondary. The is_primary
        // filter on the primary lookup forces us through the fallback,
        // and the fallback's primary-backed disambiguator returns
        // empty, so we INSERT — and the eventual primary collision in
        // syncIdentifiers throws as 502, surfacing the corruption
        // instead of routing future sync/push through the wrong
        // bank_account.
        $bank = Bank::query()->findOrFail('mock');

        // Pre-existing EUR row with its own primary AND a same-currency
        // sibling-shared secondary.
        $existingEur = BankAccount::query()->create([
            'bank_slug' => $bank->slug,
            'iban' => null,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);
        BankAccountIdentifier::query()->create([
            'bank_account_id' => $existingEur->id,
            'hash' => 'HASH-EUR-A-PRIMARY',
            'is_primary' => true,
            'first_seen_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);
        BankAccountIdentifier::query()->create([
            'bank_account_id' => $existingEur->id,
            'hash' => 'HASH-PROMOTED-SHARED',
            'is_primary' => false,
            'first_seen_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);

        // EB returns a same-currency sibling whose primary is now the
        // hash that was previously a non-primary on the existing row.
        $payload = [
            'session_id' => 'session-promoted',
            'accounts' => [[
                'uid' => 'uid-eur-sibling',
                'name' => 'Same Holder',
                'currency' => 'EUR',
                'cash_account_type' => 'CACC',
                'identification_hash' => 'HASH-PROMOTED-SHARED',
                'identification_hashes' => ['HASH-PROMOTED-SHARED'],
            ]],
            'aspsp' => ['name' => 'Mock ASPSP', 'country' => 'FI'],
            'psu_type' => 'personal',
            'access' => ['valid_until' => Carbon::now()->addDays(90)->toIso8601ZuluString()],
        ];

        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($payload, 200)]);
        $auth = $this->openAuthRequest();

        // Surfaces as a 502 rather than silently rebinding the row.
        $this->get("/banking/callback?code=fake&state={$auth->state}")->assertStatus(502);

        // Existing row is untouched.
        $existingEur->refresh();
        $this->assertSame('EUR', $existingEur->currency);
        $existingPrimary = BankAccountIdentifier::query()->where('hash', 'HASH-EUR-A-PRIMARY')->sole();
        $this->assertSame($existingEur->id, $existingPrimary->bank_account_id);
        $this->assertTrue($existingPrimary->is_primary);

        // No phantom row inserted.
        $this->assertSame(1, BankAccount::query()->count());
    }

    public function test_callback_rejects_when_fallback_finds_multiple_primary_backed_candidates(): void
    {
        // Codex round 10 P1: a sibling that retained a shared secondary
        // later makes that hash its current primary; a re-consent for
        // the OTHER sibling with a rotated primary then puts TWO rows
        // into the primary-backed candidate set (the real sibling via
        // its old primary AND the promoted sibling via the shared hash).
        // The fallback must refuse to pick — falling through to insert
        // would silently strand the original row's sync state because
        // syncIdentifiers tolerantly skips the secondary-collisions.
        $bank = Bank::query()->findOrFail('mock');

        // Row A: primary is HASH-SHARED (promoted from a former shared
        // secondary) — models the post-rotation state.
        $rowA = BankAccount::query()->create([
            'bank_slug' => $bank->slug,
            'iban' => null,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);
        BankAccountIdentifier::query()->create([
            'bank_account_id' => $rowA->id,
            'hash' => 'HASH-SHARED',
            'is_primary' => true,
            'first_seen_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);

        // Row B: primary is HASH-B-OLD-PRIMARY — the "true" sibling
        // that's about to rotate.
        $rowB = BankAccount::query()->create([
            'bank_slug' => $bank->slug,
            'iban' => null,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);
        BankAccountIdentifier::query()->create([
            'bank_account_id' => $rowB->id,
            'hash' => 'HASH-B-OLD-PRIMARY',
            'is_primary' => true,
            'first_seen_at' => Carbon::now()->subDay(),
            'last_seen_at' => Carbon::now()->subDay(),
        ]);

        // EB returns B with a rotated primary, allHashes overlapping both
        // B's old primary AND the now-promoted shared hash (still on A).
        $payload = [
            'session_id' => 'session-ambiguous',
            'accounts' => [[
                'uid' => 'uid-b-rotated',
                'name' => 'Same Holder',
                'currency' => 'EUR',
                'cash_account_type' => 'CACC',
                'identification_hash' => 'HASH-B-NEW-PRIMARY',
                'identification_hashes' => ['HASH-B-NEW-PRIMARY', 'HASH-B-OLD-PRIMARY', 'HASH-SHARED'],
            ]],
            'aspsp' => ['name' => 'Mock ASPSP', 'country' => 'FI'],
            'psu_type' => 'personal',
            'access' => ['valid_until' => Carbon::now()->addDays(90)->toIso8601ZuluString()],
        ];

        Http::fake(['https://api.enablebanking.test/sessions' => Http::response($payload, 200)]);
        $auth = $this->openAuthRequest();

        // Refuses ambiguous match — surfaces as 502 instead of silently
        // duplicating the row.
        $this->get("/banking/callback?code=fake&state={$auth->state}")->assertStatus(502);

        // Both pre-existing rows untouched, no phantom row inserted.
        $this->assertSame(2, BankAccount::query()->count());
        $this->assertNotNull(BankAccount::query()->find($rowA->id));
        $this->assertNotNull(BankAccount::query()->find($rowB->id));
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

        // auth_request IS consumed: the one-time code was spent on EB even though the
        // exchange failed, so the row must reflect that. Operators retry by running
        // spendula:auth:start fresh, not by re-hitting the same callback URL.
        $auth->refresh();
        $this->assertNotNull($auth->consumed_at);
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
