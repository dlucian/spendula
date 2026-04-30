<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\BankConnectionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankAccountSession;
use App\Models\BankConnection;
use App\Models\TrackingSnapshot;
use App\Services\EnableBanking\Jwt;
use App\Services\ExchangeRates\Exceptions\ExchangeRateProviderUnreachableException;
use App\Services\ExchangeRates\Rate;
use App\Services\ExchangeRates\RateProvider;
use App\Services\Locks\AdvisoryLock;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class TrackingSnapshotCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string EB_BASE_URL = 'https://api.enablebanking.test';

    private const string YNAB_BASE_URL = 'https://api.ynab.test/v1';

    private const string YNAB_PLAN_ID = 'plan-under-test';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-29 12:00:00'));
        Sleep::fake();

        config()->set('spendula.base_currency', 'EUR');
        config()->set('spendula.ynab.access_token', 'test-token');
        config()->set('spendula.ynab.plan_id', self::YNAB_PLAN_ID);
        config()->set('spendula.ynab.base_url', self::YNAB_BASE_URL);
        config()->set('spendula.enable_banking.base_url', self::EB_BASE_URL);

        Bank::query()->create([
            'slug' => 'mock',
            'display_name' => 'Mock',
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

    public function test_pushes_balance_adjustment_and_records_snapshot(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-ron');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-ron/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '1234.56', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => [
                    'id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
                    'balance' => 100_000,
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => [
                    'transactions' => [[
                        'id' => 'ynab-tx-snapshot-1',
                    ]],
                    'duplicate_import_ids' => [],
                ],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();

        // 1234560 native milliunits * 0.20000 = 246912 EUR milliunits.
        // delta = 246912 - 100000 = 146912.
        $expectedNative = 1_234_560;
        $expectedBase = 246_912;
        $expectedDelta = $expectedBase - 100_000;

        Http::assertSent(function (Request $request) use ($expectedDelta): bool {
            if ($request->url() !== self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions') {
                return false;
            }
            $body = $request->data();
            $tx = $body['transactions'][0] ?? null;

            return is_array($tx)
                && $tx['account_id'] === 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa'
                && $tx['amount'] === $expectedDelta
                && $tx['payee_name'] === 'Balance Adjustment'
                && $tx['cleared'] === 'reconciled'
                && $tx['approved'] === true
                && $tx['date'] === '2026-04-29'
                && str_contains((string) $tx['memo'], 'FX snapshot:')
                && str_contains((string) $tx['memo'], '1234.56 RON')
                && str_contains((string) $tx['memo'], 'rate 0.20000')
                && str_contains((string) $tx['memo'], 'as of 2026-04-29')
                && is_string($tx['import_id'])
                && strlen($tx['import_id']) === 36
                && str_starts_with($tx['import_id'], 'SPNDL:');
        });

        $snapshot = TrackingSnapshot::query()->sole();
        $this->assertSame($account->id, $snapshot->bank_account_id);
        $this->assertSame('2026-04-29', $snapshot->as_of_date->toDateString());
        $this->assertSame($expectedNative, $snapshot->native_balance_milliunits);
        $this->assertSame($expectedBase, $snapshot->base_balance_milliunits);
        $this->assertSame(0, bccomp((string) $snapshot->exchange_rate, '0.20000', 8));
        $this->assertSame('frankfurter', $snapshot->exchange_rate_source);
        $this->assertSame('ynab-tx-snapshot-1', $snapshot->ynab_transaction_id);
        $this->assertNotNull($snapshot->pushed_at);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-ron');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-ron/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '1000.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 100_000]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'should-not-be-called']], 'duplicate_import_ids' => []],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot', ['--dry-run' => true])->assertSuccessful();

        // No createTransactions POST, no snapshot row.
        Http::assertNotSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions';
        });
        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    public function test_account_filter_scopes_to_one(): void
    {
        $this->bindStubJwt();
        $account1 = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account1, 'uid-1');

        $account2 = $this->seedTrackingAccount(
            ynabAccountId: 'bbbbbbbb-2222-bbbb-2222-bbbbbbbbbbbb',
            currency: 'RON',
        );
        $this->seedSession($account2, 'uid-2');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-1/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '500.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 100_000]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'tx-1']], 'duplicate_import_ids' => []],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot', ['--account' => $account1->id])->assertSuccessful();

        $this->assertSame(1, TrackingSnapshot::query()->count());
        $this->assertSame($account1->id, TrackingSnapshot::query()->sole()->bank_account_id);
    }

    public function test_invalid_account_uuid_errors(): void
    {
        $this->bindStubJwt();
        Http::fake();

        $this->artisan('spendula:tracking:snapshot', ['--account' => '00000000-0000-0000-0000-000000000000'])
            ->expectsOutputToContain("No bank_account with id '00000000-0000-0000-0000-000000000000'.")
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    public function test_non_tracking_account_rejected(): void
    {
        $this->bindStubJwt();
        $onBudget = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'ynab_account_id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            'ynab_account_type' => YnabAccountType::OnBudget,
            'import_cutoff_date' => Carbon::parse('2026-01-01'),
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        Http::fake();

        $this->artisan('spendula:tracking:snapshot', ['--account' => $onBudget->id])
            ->expectsOutputToContain('is not tracking-mapped')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    public function test_idempotent_same_day_two_rows_delta_zero(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-ron');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-ron/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '1000.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
            // First run: YNAB balance starts at 0; expected 1000 RON * 0.20 = 200 EUR -> 200_000 milliunits.
            // After first push, YNAB now has 200_000 (we simulate by sequencing the response).
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::sequence()
                ->push(['data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 0]]], 200)
                ->push(['data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 200_000]]], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::sequence()
                ->push(['data' => ['transactions' => [['id' => 'tx-first']], 'duplicate_import_ids' => []]], 201)
                ->push(['data' => ['transactions' => [['id' => 'tx-second']], 'duplicate_import_ids' => []]], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();
        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();

        $snapshots = TrackingSnapshot::query()->orderBy('pushed_at')->get();
        $this->assertCount(2, $snapshots);
        $this->assertSame(200_000, $snapshots[0]->base_balance_milliunits);
        $this->assertSame(200_000, $snapshots[1]->base_balance_milliunits);

        // Second push delta should be ~0 (within ±1 milliunit per spec).
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            if ($request->url() !== self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions') {
                return false;
            }
            $tx = $request->data()['transactions'][0] ?? null;

            return is_array($tx) && $tx['amount'] === 0;
        });
    }

    public function test_advisory_lock_busy_exits(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-ron');

        // Postgres advisory locks are per-session; the test process and the
        // artisan command share the test's DB connection, so a same-session
        // tryAcquire would be re-entrant. Acquire the lock from a
        // *separate* connection (a fresh pgsql config) so the command's
        // tryAcquire actually fails. The connection persists in the
        // container as long as we keep the PDO open.
        $secondPdo = $this->openSecondConnectionAndLock(AdvisoryLock::TRACKING_SNAPSHOT);

        try {
            $this->artisan('spendula:tracking:snapshot')
                ->expectsOutputToContain('Another tracking snapshot is already running.')
                ->assertFailed();
        } finally {
            // Release explicitly; closing the PDO would also release.
            $secondPdo->exec('SELECT pg_advisory_unlock('.AdvisoryLock::TRACKING_SNAPSHOT.')');
            $secondPdo = null;
        }

        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    private function openSecondConnectionAndLock(int $lockKey): \PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database'],
        );
        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query("SELECT pg_try_advisory_lock({$lockKey}) AS acquired");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (! $row || ! $row['acquired']) {
            throw new \RuntimeException('Could not acquire lock from separate connection.');
        }

        return $pdo;
    }

    public function test_rate_provider_unreachable_aborts(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-ron');

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-ron/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '1000.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
        ]);

        $this->app->bind(RateProvider::class, fn () => new ThrowingRateProvider);

        $this->artisan('spendula:tracking:snapshot')
            ->expectsOutputToContain('Exchange rate provider unreachable; no snapshot taken.')
            ->assertFailed();

        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    public function test_enable_banking_unreachable_per_account_continues(): void
    {
        $this->bindStubJwt();
        $bad = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($bad, 'uid-bad');

        $good = $this->seedTrackingAccount(
            ynabAccountId: 'bbbbbbbb-2222-bbbb-2222-bbbbbbbbbbbb',
            currency: 'RON',
        );
        $this->seedSession($good, 'uid-good');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-bad/balances' => Http::response(['error' => 'boom'], 503),
            self::EB_BASE_URL.'/accounts/uid-good/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '500.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/bbbbbbbb-2222-bbbb-2222-bbbbbbbbbbbb' => Http::response([
                'data' => ['account' => ['id' => 'bbbbbbbb-2222-bbbb-2222-bbbbbbbbbbbb', 'balance' => 50_000]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'tx-good']], 'duplicate_import_ids' => []],
            ], 201),
        ]);

        // Exit 0 because at least one account succeeded.
        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();

        $snapshot = TrackingSnapshot::query()->sole();
        $this->assertSame($good->id, $snapshot->bank_account_id);
    }

    public function test_all_accounts_failing_returns_nonzero(): void
    {
        $this->bindStubJwt();
        $a = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($a, 'uid-a');

        $b = $this->seedTrackingAccount(
            ynabAccountId: 'bbbbbbbb-2222-bbbb-2222-bbbbbbbbbbbb',
            currency: 'RON',
        );
        $this->seedSession($b, 'uid-b');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-a/balances' => Http::response(['error' => 'boom'], 503),
            self::EB_BASE_URL.'/accounts/uid-b/balances' => Http::response(['error' => 'boom'], 503),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertFailed();
        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    public function test_eb_balance_currency_mismatch_aborts_account(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-mismatch');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        // EB returns a USD balance for a RON-keyed account. Pushing the
        // RON→EUR converted delta would corrupt the YNAB Balance Adjustment.
        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-mismatch/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '500.00', 'currency' => 'USD'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertFailed();
        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    public function test_eb_balance_lowercase_credit_debit_indicator_is_accepted(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-lowercase');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        // Some ASPSPs emit lowercase 'crdt'/'dbit'. The command must normalize
        // case before the strict allowlist check (matches MatchUpdateOrInsert).
        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-lowercase/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '500.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'crdt',
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 50_000]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'tx-lowercase']], 'duplicate_import_ids' => []],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();
        $this->assertSame(1, TrackingSnapshot::query()->count());
    }

    public function test_eb_balance_missing_cdi_with_positive_amount_infers_crdt(): void
    {
        // Real Revolut and ING omit `credit_debit_indicator` from balance
        // entries and rely on the amount's sign. Positive amount → CRDT.
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-no-cdi-pos');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-no-cdi-pos/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '500.00', 'currency' => 'RON'],
                    // credit_debit_indicator intentionally omitted
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 0]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'ynab-tx-1', 'import_id' => 'SPNDL:imported']]],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();
        $this->assertSame(1, TrackingSnapshot::query()->count());
        $snap = TrackingSnapshot::query()->first();
        $this->assertSame(500_000, (int) $snap->native_balance_milliunits);
    }

    public function test_eb_balance_missing_cdi_with_negative_amount_infers_dbit(): void
    {
        // Overdraft / liability case: a tracking account with a negative
        // signed amount and no explicit CDI must yield negative milliunits.
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-no-cdi-neg');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-no-cdi-neg/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '-100.00', 'currency' => 'RON'],
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 0]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'ynab-tx-2', 'import_id' => 'SPNDL:imported']]],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();
        $snap = TrackingSnapshot::query()->first();
        $this->assertSame(-100_000, (int) $snap->native_balance_milliunits);
    }

    public function test_eb_balance_missing_cdi_with_explicit_plus_amount_infers_crdt(): void
    {
        // Berlin Group permits an explicit leading `+` on signed amounts.
        // Money::toMilliunits only accepts an optional `-`, so the leading
        // `+` must be stripped before inference + conversion. Without the
        // strip, the account would be skipped as malformed.
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-no-cdi-plus');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-no-cdi-plus/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '+500.00', 'currency' => 'RON'],
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 0]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'ynab-tx-plus', 'import_id' => 'SPNDL:imported']]],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();
        $snap = TrackingSnapshot::query()->first();
        $this->assertSame(500_000, (int) $snap->native_balance_milliunits);
    }

    public function test_eb_balance_invalid_cdi_value_still_aborts(): void
    {
        // When CDI IS present, garbage values must still be rejected — a
        // typo on the bank side shouldn't be silently re-interpreted from
        // the amount sign.
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-bad-cdi');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-bad-cdi/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '500.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'WHATEVER',
                ]],
            ], 200),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertFailed();
        $this->assertSame(0, TrackingSnapshot::query()->count());
    }

    public function test_eb_balance_iso20022_balance_type_codes_are_recognized(): void
    {
        // Real ASPSPs emit Berlin Group ISO 20022 codes (`ITAV`, `XPCD`,
        // `CLBD`) as `balance_type` rather than the canonical lowercase
        // names. The preference ladder must walk both vocabularies so
        // ITAV is preferred over XPCD even when both forms appear.
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-iso');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-iso/balances' => Http::response([
                'balances' => [
                    [
                        'balance_type' => 'XPCD',
                        'balance_amount' => ['amount' => '999.00', 'currency' => 'RON'],
                    ],
                    [
                        'balance_type' => 'ITAV',
                        'balance_amount' => ['amount' => '214.29', 'currency' => 'RON'],
                    ],
                ],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 0]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [['id' => 'ynab-tx-3', 'import_id' => 'SPNDL:imported']]],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();
        $snap = TrackingSnapshot::query()->first();
        // Picked the ITAV entry (214.29), not the XPCD entry (999.00).
        $this->assertSame(214_290, (int) $snap->native_balance_milliunits);
    }

    public function test_ynab_returns_only_duplicate_import_ids_records_snapshot(): void
    {
        $this->bindStubJwt();
        $account = $this->seedTrackingAccount(
            ynabAccountId: 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa',
            currency: 'RON',
        );
        $this->seedSession($account, 'uid-dup');

        $this->stubRateProvider('RON', 'EUR', '0.20000', Carbon::parse('2026-04-29'));

        Http::fake([
            self::EB_BASE_URL.'/accounts/uid-dup/balances' => Http::response([
                'balances' => [[
                    'balance_type' => 'interim_available',
                    'balance_amount' => ['amount' => '500.00', 'currency' => 'RON'],
                    'credit_debit_indicator' => 'CRDT',
                ]],
            ], 200),
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/accounts/aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa' => Http::response([
                'data' => ['account' => ['id' => 'aaaaaaaa-1111-aaaa-1111-aaaaaaaaaaaa', 'balance' => 50_000]],
            ], 200),
            // YNAB dedups a retry: returns no `transactions` array, only the
            // import_id we sent. The command must accept this shape and still
            // record a snapshot row using the import_id as the identifier.
            self::YNAB_BASE_URL.'/plans/'.self::YNAB_PLAN_ID.'/transactions' => Http::response([
                'data' => ['transactions' => [], 'duplicate_import_ids' => ['SPNDL:abcdef0123456789abcdef0123456789']],
            ], 201),
        ]);

        $this->artisan('spendula:tracking:snapshot')->assertSuccessful();

        $snapshot = TrackingSnapshot::query()->sole();
        $this->assertSame($account->id, $snapshot->bank_account_id);
        $this->assertSame('SPNDL:abcdef0123456789abcdef0123456789', $snapshot->ynab_transaction_id);
    }

    private function seedTrackingAccount(string $ynabAccountId, string $currency): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => $currency,
            'is_base_currency' => false,
            'active' => true,
            'ynab_account_id' => $ynabAccountId,
            'ynab_account_type' => YnabAccountType::Tracking,
            'import_cutoff_date' => Carbon::parse('2026-01-01'),
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    private function seedSession(BankAccount $account, string $uid): BankAccountSession
    {
        // bank_connections has a partial unique index forcing one active
        // connection per bank_slug. Reuse the existing active connection
        // when seeding multiple accounts on the same bank.
        $connection = BankConnection::query()
            ->where('bank_slug', $account->bank_slug)
            ->where('status', BankConnectionStatus::Active->value)
            ->first();

        if (! $connection instanceof BankConnection) {
            $connection = BankConnection::query()->create([
                'bank_slug' => $account->bank_slug,
                'enable_banking_session_id' => 'session-'.$uid,
                'status' => BankConnectionStatus::Active,
                'authorized_at' => Carbon::now(),
                'valid_until' => Carbon::now()->addDays(90),
                'raw_session_response' => [],
            ]);
        }

        /** @var BankAccountSession $session */
        $session = BankAccountSession::query()->create([
            'bank_connection_id' => $connection->id,
            'bank_account_id' => $account->id,
            'enable_banking_uid' => $uid,
        ]);

        return $session;
    }

    private function stubRateProvider(string $base, string $quote, string $rate, CarbonInterface $rateDate): void
    {
        $this->app->bind(RateProvider::class, fn () => new StaticRateProvider($base, $quote, $rate, $rateDate));
    }

    /**
     * The TrackingSnapshotCommand resolves EnableBankingClient via the
     * container (which constructs Jwt::fromConfig() requiring a real
     * private key on disk). Bind a stub so the test env doesn't need one.
     */
    private function bindStubJwt(): void
    {
        $this->app->bind(Jwt::class, fn () => new StubJwtForTracking);
    }
}

class StaticRateProvider implements RateProvider
{
    public function __construct(
        private readonly string $base,
        private readonly string $quote,
        private readonly string $rate,
        private readonly CarbonInterface $rateDate,
    ) {}

    public function getRate(string $base, string $quote, CarbonInterface $date): Rate
    {
        if (strtoupper($base) !== strtoupper($this->base) || strtoupper($quote) !== strtoupper($this->quote)) {
            throw new \InvalidArgumentException("Unexpected pair {$base}->{$quote}");
        }

        return new Rate($this->base, $this->quote, $this->rateDate, $this->rate, 'frankfurter');
    }
}

class ThrowingRateProvider implements RateProvider
{
    public function getRate(string $base, string $quote, CarbonInterface $date): Rate
    {
        throw new ExchangeRateProviderUnreachableException('rate provider down');
    }
}

class StubJwtForTracking extends Jwt
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
