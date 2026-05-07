<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CounterpartyRulesAddCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempBank;

    private string $availablePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBank = 'addtest-'.uniqid();
        $this->availablePath = base_path("config/counterparty-rules-available/{$this->tempBank}.json");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->availablePath)) {
            unlink($this->availablePath);
        }
        parent::tearDown();
    }

    public function test_creates_new_rule_file_when_none_exists(): void
    {
        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'compra')
            ->expectsQuestion('Description', 'BCP card purchase')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^COMPRA\s+\d+\s+(.+)$/i')
            ->expectsQuestion('Replacement', '$1')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'COMPRA 5962 SHOP')
            ->expectsQuestion('Expected output', 'SHOP')
            ->expectsConfirmation('Save rule?', 'yes')
            ->expectsOutputToContain('Saved')
            ->assertSuccessful();

        $this->assertFileExists($this->availablePath);
        $data = json_decode(file_get_contents($this->availablePath), true);
        $this->assertCount(1, $data['rules']);
        $this->assertSame('compra', $data['rules'][0]['name']);
    }

    public function test_appends_to_existing_rule_file(): void
    {
        file_put_contents($this->availablePath, json_encode([
            'name' => 'Test Bank',
            'rules' => [
                ['name' => 'existing', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]));

        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'new-rule')
            ->expectsQuestion('Description', 'desc')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^Y$/')
            ->expectsQuestion('Replacement', 'Z')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'Y')
            ->expectsQuestion('Expected output', 'Z')
            ->expectsConfirmation('Save rule?', 'yes')
            ->assertSuccessful();

        $data = json_decode(file_get_contents($this->availablePath), true);
        $this->assertCount(2, $data['rules']);
        $this->assertSame('existing', $data['rules'][0]['name']);
        $this->assertSame('new-rule', $data['rules'][1]['name']);
    }

    public function test_refuses_to_save_when_fixture_fails(): void
    {
        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'broken')
            ->expectsQuestion('Description', 'd')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^X$/')
            ->expectsQuestion('Replacement', 'Y')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'X')
            ->expectsQuestion('Expected output', 'Z')  // wrong: rule produces Y, expected says Z
            ->expectsOutputToContain('Fixture failed')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->availablePath);
    }

    public function test_refuses_to_save_when_regex_does_not_compile(): void
    {
        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'broken')
            ->expectsQuestion('Description', 'd')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/[broken/')
            ->expectsOutputToContain('regex')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->availablePath);
    }

    public function test_refuses_to_save_when_rule_name_already_exists(): void
    {
        file_put_contents($this->availablePath, json_encode([
            'name' => 'Test',
            'rules' => [
                ['name' => 'compra', 'description' => 'd', 'pattern' => '/^X$/', 'replacement' => '', 'tests' => [['in' => 'X', 'out' => '']]],
            ],
        ]));

        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'compra')
            ->expectsOutputToContain('already')
            ->assertFailed();
    }

    public function test_from_transaction_pulls_remittance_and_previews_impact(): void
    {
        $bankSlug = 'mock';

        Bank::query()->create([
            'slug' => $bankSlug,
            'display_name' => 'Mock',
            'aspsp_name' => 'Mock',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $account = BankAccount::query()->create([
            'bank_slug' => $bankSlug,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $tx = $this->seedTx($account, 'COMPRA 5962 AIR SERBIA A21296337235 Belgrade RS');
        $this->seedTx($account, 'COMPRA 5962 AIR SERBIA A44027514935 Belgrade RS');

        $availablePath = base_path("config/counterparty-rules-available/{$bankSlug}.json");

        try {
            $this->artisan("spendula:counterparty:rules:add --from-transaction={$tx->id}")
                ->expectsOutputToContain('Raw remittance: COMPRA 5962 AIR SERBIA')
                ->expectsQuestion('Rule name (kebab-case)', 'air-serbia')
                ->expectsQuestion('Description', 'Air Serbia flight booking')
                ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^COMPRA\s+\d+\s+(AIR\s+SERBIA)\s+[A-Z]\d+\s+(.+)$/i')
                ->expectsQuestion('Replacement', '$1 $2')
                ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
                ->expectsOutputToContain('Auto-derived expected output: AIR SERBIA Belgrade RS')
                ->expectsConfirmation('Use this as the fixture?', 'yes')
                ->expectsOutputToContain('row(s) would change')
                ->expectsConfirmation('Save rule?', 'yes')
                ->assertSuccessful();
        } finally {
            // Cleanup: remove the rule we added (only if it's the one this test added — preserve other rules)
            if (file_exists($availablePath)) {
                $existing = json_decode(file_get_contents($availablePath), true);
                $existing['rules'] = array_values(array_filter(
                    $existing['rules'] ?? [],
                    fn ($r) => ($r['name'] ?? '') !== 'air-serbia',
                ));
                if (count($existing['rules']) === 0) {
                    unlink($availablePath);
                } else {
                    file_put_contents(
                        $availablePath,
                        json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
                    );
                }
            }
        }
    }

    public function test_from_transaction_preview_excludes_rows_resolved_at_l0(): void
    {
        $bankSlug = 'l0test';

        Bank::query()->create([
            'slug' => $bankSlug,
            'display_name' => 'L0 Test',
            'aspsp_name' => 'L0 Test',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $account = BankAccount::query()->create([
            'bank_slug' => $bankSlug,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        // The "from" transaction: no creditor/debtor in payload, so L0/L1 don't
        // fire and the candidate rule (L2) is reached — this row WILL be counted.
        $tx = $this->seedTx($account, 'COMPRA 5962 AIR SERBIA A21296337235 Belgrade RS');

        // A second transaction with a populated creditor name. DBIT → creditor
        // resolves at L0 before the rule engine is ever called — this row must
        // NOT appear in the impact count.
        Transaction::query()->create([
            'bank_account_id' => $account->id,
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -10_000,
            'currency' => 'EUR',
            'credit_debit_indicator' => 'DBIT',
            'counterparty_name' => 'Already Resolved',
            'counterparty_resolution_level' => 0,
            'remittance_information' => 'COMPRA 5962 AIR SERBIA A44027514935 Belgrade RS',
            'raw_payload' => [
                'remittance_information' => ['COMPRA 5962 AIR SERBIA A44027514935 Belgrade RS'],
                'credit_debit_indicator' => 'DBIT',
                'creditor' => ['name' => 'Already Resolved'],
            ],
            'dedup_hash' => str_pad(bin2hex(random_bytes(16)), 32, '0'),
            'occurrence' => 1,
            'status' => TransactionStatus::Fetched->value,
            'transaction_status' => 'BOOK',
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);

        $availablePath = base_path("config/counterparty-rules-available/{$bankSlug}.json");

        try {
            $this->artisan("spendula:counterparty:rules:add --from-transaction={$tx->id}")
                ->expectsOutputToContain('Raw remittance: COMPRA 5962 AIR SERBIA')
                ->expectsQuestion('Rule name (kebab-case)', 'air-serbia-l0')
                ->expectsQuestion('Description', 'Air Serbia flight booking')
                ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^COMPRA\s+\d+\s+(AIR\s+SERBIA)\s+[A-Z]\d+\s+(.+)$/i')
                ->expectsQuestion('Replacement', '$1 $2')
                ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
                ->expectsOutputToContain('Auto-derived expected output: AIR SERBIA Belgrade RS')
                ->expectsConfirmation('Use this as the fixture?', 'yes')
                ->expectsOutputToContain('1 row(s) would change')  // only the L2 row, not the L0 row
                ->expectsConfirmation('Save rule?', 'yes')
                ->assertSuccessful();
        } finally {
            if (file_exists($availablePath)) {
                $existing = json_decode(file_get_contents($availablePath), true);
                $existing['rules'] = array_values(array_filter(
                    $existing['rules'] ?? [],
                    fn ($r) => ($r['name'] ?? '') !== 'air-serbia-l0',
                ));
                if (count($existing['rules']) === 0) {
                    unlink($availablePath);
                } else {
                    file_put_contents(
                        $availablePath,
                        json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
                    );
                }
            }
        }
    }

    public function test_refuses_to_save_when_new_rule_is_shadowed_by_existing_rule(): void
    {
        // Existing file has a generic catch-all that matches "COMPRA 5962 *".
        file_put_contents($this->availablePath, json_encode([
            'name' => 'Test',
            'rules' => [
                [
                    'name' => 'compra-generic',
                    'description' => 'catches everything COMPRA',
                    'pattern' => '/^COMPRA\s+\d{3,5}\s+(.+)$/i',
                    'replacement' => '$1',
                    'tests' => [['in' => 'COMPRA 5962 SHOP', 'out' => 'SHOP']],
                ],
            ],
        ]));

        // Operator tries to add a more-specific rule for AIR SERBIA, but
        // the generic rule above will fire first and produce a different
        // result.
        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'compra-air-serbia')
            ->expectsQuestion('Description', 'AIR SERBIA flight booking')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^COMPRA\s+\d{3,5}\s+(AIR\s+SERBIA)\s+[A-Z]\d{8,}\s+(.+)$/i')
            ->expectsQuestion('Replacement', '$1 $2')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'COMPRA 5962 AIR SERBIA A21296337235 Belgrade RS')
            ->expectsQuestion('Expected output', 'AIR SERBIA Belgrade RS')
            ->expectsOutputToContain('shadowed')
            ->assertFailed();

        // Existing file unchanged
        $data = json_decode(file_get_contents($this->availablePath), true);
        $this->assertCount(1, $data['rules']);
        $this->assertSame('compra-generic', $data['rules'][0]['name']);
    }

    public function test_refuses_to_overwrite_malformed_existing_file(): void
    {
        file_put_contents($this->availablePath, '{ not json');

        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsOutputToContain('malformed JSON')
            ->expectsOutputToContain('Refusing to overwrite')
            ->assertFailed();

        // File unchanged
        $this->assertSame('{ not json', file_get_contents($this->availablePath));
    }

    public function test_refuses_to_overwrite_existing_file_missing_rules_array(): void
    {
        file_put_contents($this->availablePath, json_encode(['name' => 'Test']));

        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsOutputToContain("missing the 'rules' array")
            ->expectsOutputToContain('Refusing to overwrite')
            ->assertFailed();

        // File unchanged
        $data = json_decode(file_get_contents($this->availablePath), true);
        $this->assertSame(['name' => 'Test'], $data);
    }

    public function test_appending_preserves_top_level_description(): void
    {
        file_put_contents($this->availablePath, json_encode([
            'name' => 'Test Bank',
            'description' => 'A description that must be preserved when rules are added',
            'rules' => [],
        ]));

        $this->artisan("spendula:counterparty:rules:add --bank={$this->tempBank}")
            ->expectsQuestion('Rule name (kebab-case)', 'r1')
            ->expectsQuestion('Description', 'd')
            ->expectsQuestion('Pattern (full PCRE, e.g. /^X$/i)', '/^X$/')
            ->expectsQuestion('Replacement', 'Y')
            ->expectsQuestion('Post hooks (comma-separated; blank for none)', '')
            ->expectsQuestion('Fixture input', 'X')
            ->expectsQuestion('Expected output', 'Y')
            ->expectsConfirmation('Save rule?', 'yes')
            ->assertSuccessful();

        $data = json_decode(file_get_contents($this->availablePath), true);
        $this->assertSame('A description that must be preserved when rules are added', $data['description']);
        $this->assertCount(1, $data['rules']);
    }

    private function seedTx(BankAccount $account, string $remittance): Transaction
    {
        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -10_000,
            'currency' => 'EUR',
            'credit_debit_indicator' => 'DBIT',
            'counterparty_name' => null,
            'counterparty_resolution_level' => 2,
            'remittance_information' => $remittance,
            'raw_payload' => [
                'remittance_information' => [$remittance],
                'credit_debit_indicator' => 'DBIT',
            ],
            'dedup_hash' => str_pad(bin2hex(random_bytes(16)), 32, '0'),
            'occurrence' => 1,
            'status' => TransactionStatus::Fetched->value,
            'transaction_status' => 'BOOK',
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
