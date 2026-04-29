<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountsMapCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze the clock so import_cutoff_date assertions can't drift
        // across midnight while the test runs.
        Carbon::setTestNow(Carbon::parse('2026-04-29 12:00:00'));

        config()->set('spendula.ynab.access_token', 'test-pat');
        config()->set('spendula.ynab.plan_id', 'plan-uuid');
        config()->set('spendula.ynab.base_url', 'https://api.ynab.test/v1');
        config()->set('spendula.base_currency', 'EUR');

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_non_interactive_mapping_with_explicit_flags(): void
    {
        $account = $this->seedAccount(currency: 'EUR');
        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee',
        ])->assertSuccessful();

        $account->refresh();
        $this->assertSame('aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', $account->ynab_account_id);
        $this->assertSame(YnabAccountType::OnBudget, $account->ynab_account_type);
        $this->assertSame('Checking', $account->display_name);
        $this->assertSame(Carbon::today()->toDateString(), $account->import_cutoff_date->toDateString());
    }

    public function test_non_base_currency_account_maps_as_tracking(): void
    {
        $account = $this->seedAccount(currency: 'RON');
        $this->fakeYnabAccounts([
            ['id' => 'cccccccc-eeee-3333-cccc-eeeeeeeeeeee', 'name' => 'Revolut RON', 'type' => 'otherAsset', 'currency' => 'RON'],
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => 'cccccccc-eeee-3333-cccc-eeeeeeeeeeee',
        ])->assertSuccessful();

        $account->refresh();
        $this->assertSame(YnabAccountType::Tracking, $account->ynab_account_type);
    }

    public function test_unknown_ynab_account_id_fails(): void
    {
        $account = $this->seedAccount(currency: 'EUR');
        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => 'does-not-exist',
        ])
            ->expectsOutputToContain("YNAB account 'does-not-exist' not found")
            ->assertFailed();

        $this->assertNull($account->fresh()->ynab_account_id);
    }

    public function test_interactive_walkthrough_maps_unmapped_accounts(): void
    {
        $eur = $this->seedAccount(currency: 'EUR', iban: 'PT500033...7805');
        $ron = $this->seedAccount(currency: 'RON', iban: 'RO83REVO...7300');

        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Millennium BCP', 'type' => 'checking', 'currency' => 'EUR'],
            ['id' => 'bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee', 'name' => 'Revolut RON', 'type' => 'otherAsset', 'currency' => 'RON'],
        ]);

        $eurPrefix = substr($eur->id, 0, 8);
        $ronPrefix = substr($ron->id, 0, 8);

        $this->artisan('spendula:accounts:map')
            // EUR row first — base currency, so ALL YNAB accounts are
            // offered (filter only narrows for foreign currency).
            ->expectsChoice(
                "Pick a YNAB account for EUR PT500033...7805 [{$eurPrefix}]",
                'Millennium BCP (checking, on_budget=true) [aaaaaaaa]',
                [
                    'Millennium BCP (checking, on_budget=true) [aaaaaaaa]',
                    'Revolut RON (otherAsset, on_budget=false) [bbbbbbbb]',
                    '[skip this account]',
                ],
            )
            ->expectsQuestion('Display name', 'Millennium BCP')
            ->expectsQuestion('Import cutoff date (YYYY-MM-DD)', '2026-04-01')
            // RON row second — foreign currency, only tracking YNAB
            // targets are offered (Millennium BCP filtered out).
            ->expectsChoice(
                "Pick a YNAB account for RON RO83REVO...7300 [{$ronPrefix}]",
                'Revolut RON (otherAsset, on_budget=false) [bbbbbbbb]',
                ['Revolut RON (otherAsset, on_budget=false) [bbbbbbbb]', '[skip this account]'],
            )
            ->expectsQuestion('Display name', 'Revolut RON')
            ->expectsQuestion('Import cutoff date (YYYY-MM-DD)', '2026-04-01')
            ->expectsOutputToContain('mapped=2 skipped=0')
            ->assertSuccessful();

        $eur->refresh();
        $this->assertSame('aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', $eur->ynab_account_id);
        $this->assertSame(YnabAccountType::OnBudget, $eur->ynab_account_type);
        $this->assertSame('2026-04-01', $eur->import_cutoff_date->toDateString());

        $ron->refresh();
        $this->assertSame('bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee', $ron->ynab_account_id);
        $this->assertSame(YnabAccountType::Tracking, $ron->ynab_account_type);
    }

    public function test_skip_choice_leaves_account_unmapped(): void
    {
        $account = $this->seedAccount(currency: 'EUR', iban: 'EUR-IBAN');

        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        $prefix = substr($account->id, 0, 8);
        $this->artisan('spendula:accounts:map')
            ->expectsChoice(
                "Pick a YNAB account for EUR EUR-IBAN [{$prefix}]",
                '[skip this account]',
                ['Checking (checking, on_budget=true) [aaaaaaaa]', '[skip this account]'],
            )
            ->expectsOutputToContain('mapped=0 skipped=1')
            ->assertSuccessful();

        $this->assertNull($account->fresh()->ynab_account_id);
    }

    public function test_no_unmapped_accounts_exits_cleanly(): void
    {
        $this->fakeYnabAccounts([]);

        $this->artisan('spendula:accounts:map')
            ->expectsOutputToContain('No unmapped bank accounts')
            ->assertSuccessful();
    }

    public function test_foreign_currency_account_only_offered_tracking_targets(): void
    {
        // Foreign-currency bank_account: RON inside an EUR plan must only
        // be offered tracking-type YNAB accounts (on_budget=false), since
        // YNAB plans are single-currency (the plan is EUR; all YNAB
        // accounts inherit EUR regardless of source bank currency).
        $ron = $this->seedAccount(currency: 'RON', iban: 'RO-IBAN');

        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Checking',  'type' => 'checking',   'currency' => 'EUR', 'on_budget' => true],
            ['id' => 'bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee', 'name' => 'Tracker',   'type' => 'otherAsset', 'currency' => 'EUR', 'on_budget' => false],
        ]);

        $prefix = substr($ron->id, 0, 8);
        $this->artisan('spendula:accounts:map')
            ->expectsChoice(
                "Pick a YNAB account for RON RO-IBAN [{$prefix}]",
                'Tracker (otherAsset, on_budget=false) [bbbbbbbb]',
                [
                    // ONLY the tracking option appears — no on_budget Checking.
                    'Tracker (otherAsset, on_budget=false) [bbbbbbbb]',
                    '[skip this account]',
                ],
            )
            ->expectsQuestion('Display name', 'Tracker')
            ->expectsQuestion('Import cutoff date (YYYY-MM-DD)', '2026-04-29')
            ->assertSuccessful();

        $this->assertSame('bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee', $ron->fresh()->ynab_account_id);
        $this->assertSame(YnabAccountType::Tracking, $ron->fresh()->ynab_account_type);
    }

    public function test_include_mapped_flag_re_offers_already_mapped_rows(): void
    {
        $account = $this->seedAccount(currency: 'EUR', iban: 'EUR-IBAN');
        $account->ynab_account_id = 'dddddddd-eeee-4444-dddd-eeeeeeeeeeee';
        $account->ynab_account_type = YnabAccountType::OnBudget;
        $account->import_cutoff_date = Carbon::parse('2026-01-01');
        $account->save();

        $this->fakeYnabAccounts([
            ['id' => 'eeeeeeee-eeee-5555-eeee-eeeeeeeeeeee', 'name' => 'New Checking', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        // Without the flag — already-mapped row is skipped.
        $this->artisan('spendula:accounts:map')
            ->expectsOutputToContain('No unmapped bank accounts')
            ->assertSuccessful();

        $this->assertSame('dddddddd-eeee-4444-dddd-eeeeeeeeeeee', $account->fresh()->ynab_account_id);

        // With the flag — re-offered.
        $prefix = substr($account->id, 0, 8);
        $this->artisan('spendula:accounts:map', ['--include-mapped' => true])
            ->expectsChoice(
                "Pick a YNAB account for EUR EUR-IBAN [{$prefix}]",
                'New Checking (checking, on_budget=true) [eeeeeeee]',
                ['New Checking (checking, on_budget=true) [eeeeeeee]', '[skip this account]'],
            )
            ->expectsQuestion('Display name', 'New Checking')
            ->expectsQuestion('Import cutoff date (YYYY-MM-DD)', '2026-04-01')
            ->assertSuccessful();

        $this->assertSame('eeeeeeee-eeee-5555-eeee-eeeeeeeeeeee', $account->fresh()->ynab_account_id);
    }

    public function test_invalid_cutoff_date_re_prompts_then_accepts(): void
    {
        // Mistyping one date should not abort the whole walk; the prompt
        // re-asks until a valid date is entered (or up to a small retry
        // cap, after which the row is skipped — covered separately).
        $account = $this->seedAccount(currency: 'EUR', iban: 'EUR-IBAN');
        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        $prefix = substr($account->id, 0, 8);
        $this->artisan('spendula:accounts:map')
            ->expectsChoice(
                "Pick a YNAB account for EUR EUR-IBAN [{$prefix}]",
                'Checking (checking, on_budget=true) [aaaaaaaa]',
                ['Checking (checking, on_budget=true) [aaaaaaaa]', '[skip this account]'],
            )
            ->expectsQuestion('Display name', 'Checking')
            // First attempt: bad date.
            ->expectsQuestion('Import cutoff date (YYYY-MM-DD)', '2026-02-30')
            ->expectsOutputToContain("Invalid date '2026-02-30'")
            // Second attempt: valid.
            ->expectsQuestion('Import cutoff date (YYYY-MM-DD)', '2026-04-01')
            ->expectsOutputToContain('mapped=1')
            ->assertSuccessful();

        $account->refresh();
        $this->assertSame('aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', $account->ynab_account_id);
        $this->assertSame('2026-04-01', $account->import_cutoff_date->toDateString());
    }

    public function test_non_interactive_rejects_foreign_currency_to_on_budget(): void
    {
        // Currency-mismatch is no longer the right framing: YNAB accounts
        // don't carry a per-account currency. The actual constraint
        // (SPEC §4.3) is that foreign-currency bank_accounts must map to
        // YNAB tracking accounts. Non-interactive caller picking a
        // foreign-currency bank_account against an on_budget YNAB target
        // should fail loudly.
        $ron = $this->seedAccount(currency: 'RON');
        $this->fakeYnabAccounts([
            ['id' => 'bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee', 'name' => 'EUR Checking', 'type' => 'checking', 'currency' => 'EUR', 'on_budget' => true],
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $ron->id,
            '--ynab-account-id' => 'bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee',
        ])
            ->expectsOutputToContain('Foreign-currency bank_account (RON) requires a YNAB tracking account')
            ->assertFailed();

        $this->assertNull($ron->fresh()->ynab_account_id);
    }

    public function test_ynab_account_type_follows_on_budget_flag_not_currency(): void
    {
        // A base-currency bank account paired with a YNAB tracking-type
        // (on_budget=false) target must be stored as Tracking, NOT
        // OnBudget — sync/push branch on the bank_accounts column.
        $eur = $this->seedAccount(currency: 'EUR');
        $this->fakeYnabAccounts([
            [
                'id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee',
                'name' => 'EUR Tracking Asset',
                'type' => 'otherAsset',
                'currency' => 'EUR',
                'on_budget' => false,
            ],
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $eur->id,
            '--ynab-account-id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee',
        ])->assertSuccessful();

        $this->assertSame(YnabAccountType::Tracking, $eur->fresh()->ynab_account_type);
    }

    public function test_duplicate_ynab_account_names_remain_separately_addressable(): void
    {
        $account = $this->seedAccount(currency: 'EUR', iban: 'EUR-IBAN');

        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR'],
            ['id' => '11111111-eeee-9999-1111-eeeeeeeeeeee', 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        $prefix = substr($account->id, 0, 8);
        $this->artisan('spendula:accounts:map')
            ->expectsChoice(
                "Pick a YNAB account for EUR EUR-IBAN [{$prefix}]",
                'Checking (checking, on_budget=true) [11111111]',
                [
                    'Checking (checking, on_budget=true) [aaaaaaaa]',
                    'Checking (checking, on_budget=true) [11111111]',
                    '[skip this account]',
                ],
            )
            ->expectsQuestion('Display name', 'Checking')
            ->expectsQuestion('Import cutoff date (YYYY-MM-DD)', Carbon::today()->toDateString())
            ->assertSuccessful();

        $this->assertSame('11111111-eeee-9999-1111-eeeeeeeeeeee', $account->fresh()->ynab_account_id);
    }

    public function test_remap_preserves_existing_display_name_and_cutoff(): void
    {
        $account = $this->seedAccount(currency: 'EUR');
        $account->display_name = 'My Custom Label';
        $account->import_cutoff_date = Carbon::parse('2026-01-15');
        $account->ynab_account_id = 'dddddddd-eeee-4444-dddd-eeeeeeeeeeee';
        $account->ynab_account_type = YnabAccountType::OnBudget;
        $account->save();

        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'YNAB-Side Name', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        // Scripted remap with only the two required flags must not silently
        // overwrite the operator-set display_name or advance the cutoff.
        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $account->id,
            '--ynab-account-id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee',
        ])->assertSuccessful();

        $account->refresh();
        $this->assertSame('aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', $account->ynab_account_id);
        $this->assertSame('My Custom Label', $account->display_name, 'Custom display_name must survive remap.');
        $this->assertSame('2026-01-15', $account->import_cutoff_date->toDateString(), 'cutoff_date must not advance.');
    }

    public function test_only_one_of_two_scripted_flags_is_rejected(): void
    {
        $account = $this->seedAccount(currency: 'EUR');
        $this->fakeYnabAccounts([
            ['id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee', 'name' => 'Checking', 'type' => 'checking', 'currency' => 'EUR'],
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $account->id,
        ])
            ->expectsOutputToContain('--bank-account-id and --ynab-account-id must be passed together')
            ->assertFailed();

        $this->artisan('spendula:accounts:map', [
            '--ynab-account-id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee',
        ])
            ->expectsOutputToContain('--bank-account-id and --ynab-account-id must be passed together')
            ->assertFailed();
    }

    public function test_no_interaction_with_no_flags_and_pending_work_errors_out(): void
    {
        // An automation step that runs the walker with empty env vars
        // (e.g. `--bank-account-id="$BANK_ID"` where $BANK_ID is unset)
        // would otherwise fall through to the walker, get the default
        // `[skip]` answer for each prompt, and exit 0 — masking the bad
        // configuration.
        $this->seedAccount(currency: 'EUR');

        $this->artisan('spendula:accounts:map', ['--no-interaction' => true])
            ->expectsOutputToContain('No TTY available')
            ->assertFailed();
    }

    public function test_local_validation_runs_before_ynab_api_call(): void
    {
        // YNAB endpoint deliberately broken: any request fakes a 500.
        Http::fake([
            'https://api.ynab.test/v1/plans/plan-uuid/accounts' => Http::response('boom', 500),
        ]);

        // No bank_accounts seeded → empty queue. Even with YNAB broken,
        // the local short-circuit should win and the command exits 0.
        $this->artisan('spendula:accounts:map')
            ->expectsOutputToContain('No unmapped bank accounts')
            ->assertSuccessful();

        // Bad bank-account-id with the scripted flags → local error
        // before any YNAB call.
        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => '00000000-0000-0000-0000-000000000000',
            '--ynab-account-id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee',
        ])
            ->expectsOutputToContain('No bank_account with id=00000000')
            ->assertFailed();
    }

    public function test_invalid_foreign_currency_to_on_budget_pairing_fails_cleanly(): void
    {
        // Non-base-currency (RON) account paired with an on_budget YNAB
        // target. Without the pre-flight guard this turns into an
        // uncaught QueryException at save time; with it, the operator
        // sees a friendly SPEC §4.3 message.
        $ron = $this->seedAccount(currency: 'RON');
        $this->fakeYnabAccounts([
            ['id' => 'bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee', 'name' => 'RON Budget', 'type' => 'checking', 'currency' => 'RON', 'on_budget' => true],
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $ron->id,
            '--ynab-account-id' => 'bbbbbbbb-eeee-2222-bbbb-eeeeeeeeeeee',
        ])
            ->expectsOutputToContain('Foreign-currency bank_account (RON) requires a YNAB tracking account')
            ->assertFailed();

        $this->assertNull($ron->fresh()->ynab_account_id);
    }

    public function test_ynab_auth_failure_renders_clean_error(): void
    {
        $this->seedAccount(currency: 'EUR');
        Http::fake([
            'https://api.ynab.test/v1/plans/plan-uuid/accounts' => Http::response(['error' => ['name' => 'unauthorized']], 401),
        ]);

        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => '00000000-0000-0000-0000-000000000001',
            '--ynab-account-id' => '00000000-0000-0000-0000-000000000002',
        ])
            // Bad bank-account-id short-circuits BEFORE YNAB, so no YNAB error here.
            ->expectsOutputToContain('No bank_account with id=')
            ->assertFailed();

        // With a valid bank_account, YNAB 401 surfaces as a friendly error.
        $valid = $this->seedAccount(currency: 'EUR');
        $this->artisan('spendula:accounts:map', [
            '--bank-account-id' => $valid->id,
            '--ynab-account-id' => 'aaaaaaaa-eeee-1111-aaaa-eeeeeeeeeeee',
        ])
            ->expectsOutputToContain('YNAB request failed:')
            ->assertFailed();
    }

    /** @param  array<int, array<string, mixed>>  $accounts */
    private function fakeYnabAccounts(array $accounts): void
    {
        $rows = [];
        foreach ($accounts as $a) {
            $rows[] = [
                'id' => $a['id'],
                'name' => $a['name'],
                'type' => $a['type'],
                'currency' => $a['currency'] ?? 'EUR',
                'on_budget' => $a['on_budget'] ?? in_array($a['type'], ['checking', 'savings', 'cash', 'creditCard', 'lineOfCredit'], true),
                'closed' => false,
                'deleted' => false,
            ];
        }
        Http::fake([
            'https://api.ynab.test/v1/plans/plan-uuid/accounts' => Http::response([
                'data' => ['accounts' => $rows],
            ], 200),
        ]);
    }

    private function seedAccount(string $currency, ?string $iban = null): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => 'mock',
            'iban' => $iban,
            'currency' => $currency,
            'is_base_currency' => strtoupper($currency) === 'EUR',
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }
}
