<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Counterparty;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Services\Counterparty\OwnAccountClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DB-backed feature tests for OwnAccountClassifier.
 *
 * Note: placed in tests/Feature rather than tests/Unit because the classifier
 * performs DB queries (BankAccount lookups). Tests exercise the IBAN extraction,
 * own-account matching, direction-awareness, and the duplicate/inactive guards.
 */
class OwnAccountClassifierTest extends TestCase
{
    use RefreshDatabase;

    private OwnAccountClassifier $classifier;

    private BankAccount $source;

    protected function setUp(): void
    {
        parent::setUp();

        Bank::query()->create([
            'slug' => 'mock',
            'display_name' => 'Mock Bank',
            'aspsp_name' => 'Mock Bank',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $this->source = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'Source EUR',
            'iban' => null,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $this->classifier = new OwnAccountClassifier;
    }

    // -----------------------------------------------------------------------
    // Core path: DBIT, free-text "To account,"
    // -----------------------------------------------------------------------

    public function test_dbit_free_text_to_account_same_currency_returns_classification(): void
    {
        $dest = $this->createAccount('RO00BANK0000000000000001', 'EUR');

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '100.00'],
            'creditor_account' => null,
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO00BANK0000000000000001, Details, transfer',
            ],
        ], $this->source);

        $this->assertNotNull($result);
        $this->assertSame($dest->id, $result->destination->id);
        $this->assertSame('RO00BANK0000000000000001', $result->destinationIban);
        $this->assertTrue($result->sameCurrency);
    }

    public function test_dbit_free_text_to_account_different_currency_returns_classification_not_same(): void
    {
        $this->createAccount('RO00BANK0000000000000002', 'RON');

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '100.00'],
            'creditor_account' => null,
            'remittance_information' => [
                'To account, RO00BANK0000000000000002',
            ],
        ], $this->source);

        $this->assertNotNull($result);
        $this->assertFalse($result->sameCurrency);
    }

    // -----------------------------------------------------------------------
    // Core path: CRDT, free-text "From account,"
    // -----------------------------------------------------------------------

    public function test_crdt_free_text_from_account_classified_same_currency(): void
    {
        $origin = $this->createAccount('RO00BANK0000000000000003', 'EUR');

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'CRDT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '500.00'],
            'debtor_account' => null,
            'remittance_information' => [
                'Transfer, From account, RO00BANK0000000000000003, Details, internal',
            ],
        ], $this->source);

        $this->assertNotNull($result);
        $this->assertSame($origin->id, $result->destination->id);
        $this->assertTrue($result->sameCurrency);
    }

    // -----------------------------------------------------------------------
    // Structured field takes priority over free-text
    // -----------------------------------------------------------------------

    public function test_dbit_structured_creditor_account_iban_wins_over_free_text(): void
    {
        $structured = $this->createAccount('RO00BANK0000000000000004', 'EUR');
        $this->createAccount('RO00BANK0000000000000099', 'EUR'); // free-text target — should NOT win

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '100.00'],
            'creditor_account' => ['iban' => 'RO00BANK0000000000000004'],
            'remittance_information' => ['To account, RO00BANK0000000000000099'],
        ], $this->source);

        $this->assertNotNull($result);
        $this->assertSame($structured->id, $result->destination->id);
    }

    public function test_crdt_structured_debtor_account_iban_wins(): void
    {
        $structured = $this->createAccount('RO00BANK0000000000000005', 'EUR');

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'CRDT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '100.00'],
            'debtor_account' => ['iban' => 'RO00BANK0000000000000005'],
            'remittance_information' => [],
        ], $this->source);

        $this->assertNotNull($result);
        $this->assertSame($structured->id, $result->destination->id);
    }

    // -----------------------------------------------------------------------
    // Null cases
    // -----------------------------------------------------------------------

    public function test_no_iban_found_returns_null(): void
    {
        $this->createAccount('RO00BANK0000000000000006', 'EUR');

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '50.00'],
            'creditor_account' => null,
            'remittance_information' => ['just some free text with no IBAN'],
        ], $this->source);

        $this->assertNull($result);
    }

    public function test_external_iban_not_in_own_accounts_returns_null(): void
    {
        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '50.00'],
            'creditor_account' => ['iban' => 'RO99XXXX0000000000000099'],
            'remittance_information' => [],
        ], $this->source);

        $this->assertNull($result);
    }

    public function test_inactive_account_not_matched_returns_null(): void
    {
        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'Inactive',
            'iban' => 'RO00BANK0000000000000007',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => false,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '50.00'],
            'creditor_account' => ['iban' => 'RO00BANK0000000000000007'],
            'remittance_information' => [],
        ], $this->source);

        $this->assertNull($result);
    }

    public function test_self_transfer_source_iban_excluded_returns_null(): void
    {
        // Give source an IBAN, point the transaction at the same IBAN.
        $this->source->iban = 'RO00BANK0000000000000008';
        $this->source->save();

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '50.00'],
            'creditor_account' => ['iban' => 'RO00BANK0000000000000008'],
            'remittance_information' => [],
        ], $this->source->refresh());

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // Duplicate active IBAN guard (codex refinement #1)
    // -----------------------------------------------------------------------

    public function test_duplicate_active_iban_returns_null_no_override(): void
    {
        $sharedIban = 'RO00BANK0000000000000020';
        $this->createAccount($sharedIban, 'EUR', 'Account Alpha');
        $this->createAccount($sharedIban, 'EUR', 'Account Beta');

        $result = $this->classifier->classify([
            'credit_debit_indicator' => 'DBIT',
            'transaction_amount' => ['currency' => 'EUR', 'amount' => '50.00'],
            'creditor_account' => ['iban' => $sharedIban],
            'remittance_information' => [],
        ], $this->source);

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // normalizeIban
    // -----------------------------------------------------------------------

    public function test_normalize_iban_strips_whitespace_and_upcases(): void
    {
        $this->assertSame('RO00BANK0000000000000001', OwnAccountClassifier::normalizeIban('ro00 BANK 0000 0000 0000 0001'));
        $this->assertSame('GB29NWBK60161331926819', OwnAccountClassifier::normalizeIban('GB29 NWBK 6016 1331 9268 19'));
    }

    public function test_normalize_iban_empty_string_returns_empty(): void
    {
        $this->assertSame('', OwnAccountClassifier::normalizeIban(''));
        $this->assertSame('', OwnAccountClassifier::normalizeIban('   '));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createAccount(string $iban, string $currency, string $displayName = 'Dest Account'): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => $displayName,
            'iban' => $iban,
            'currency' => $currency,
            'is_base_currency' => $currency === 'EUR',
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }
}
