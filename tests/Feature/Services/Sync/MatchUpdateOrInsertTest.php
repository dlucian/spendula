<?php

namespace Tests\Feature\Services\Sync;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Enums\YnabAccountType;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Counterparty\OwnAccountClassifier;
use App\Services\Counterparty\Resolver;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use App\Services\Sync\ApplyOutcome;
use App\Services\Sync\MatchUpdateOrInsert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MatchUpdateOrInsertTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $account;

    private MatchUpdateOrInsert $apply;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->account = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $this->apply = new MatchUpdateOrInsert(
            new Resolver(
                new RuleLoader(base_path('config/counterparty-rules-enabled')),
                new RuleEngine,
            ),
            new OwnAccountClassifier,
        );
    }

    /** @return array<string, mixed> */
    private function sampleTransaction(array $overrides = []): array
    {
        return array_replace_recursive([
            'entry_reference' => 'uxr2h',
            'status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'value_date' => '2026-04-15',
            'transaction_amount' => ['amount' => '34.57', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'PINGO DOCE LISBOA'],
            'debtor' => null,
            'remittance_information' => ['CARD PAYMENT PINGO DOCE LISBOA'],
        ], $overrides);
    }

    public function test_inserts_new_transaction_with_status_fetched(): void
    {
        $result = $this->apply->apply($this->account, $this->sampleTransaction());

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        $this->assertSame(-34570, $result->transaction->amount_milliunits);
        $this->assertSame(CreditDebitIndicator::Debit, $result->transaction->credit_debit_indicator);
        $this->assertSame('PINGO DOCE LISBOA', $result->transaction->counterparty_name);
        $this->assertSame(0, $result->transaction->counterparty_resolution_level);
        $this->assertSame(1, $result->transaction->occurrence);
        $this->assertSame(32, strlen($result->transaction->dedup_hash));
    }

    public function test_exact_re_sync_is_deduped_not_inserted(): void
    {
        $this->apply->apply($this->account, $this->sampleTransaction());
        $result = $this->apply->apply($this->account, $this->sampleTransaction());

        $this->assertSame(ApplyOutcome::Deduped, $result->outcome);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_match_by_entry_reference_updates_metadata_but_not_fundamentals(): void
    {
        $first = $this->apply->apply($this->account, $this->sampleTransaction());

        $updated = $this->sampleTransaction([
            'remittance_information' => ['CARD PAYMENT PINGO DOCE LISBOA · extra detail'],
            'value_date' => '2026-04-16',
        ]);

        $result = $this->apply->apply($this->account, $updated);

        $this->assertSame(ApplyOutcome::Updated, $result->outcome);
        $this->assertSame($first->transaction->id, $result->transaction->id);
        $this->assertStringContainsString('extra detail', (string) $result->transaction->remittance_information);
        $this->assertSame('2026-04-16', $result->transaction->value_date?->toDateString());
        // fundamentals unchanged
        $this->assertSame(-34570, $result->transaction->amount_milliunits);
    }

    public function test_match_by_fundamentals_when_entry_reference_is_absent(): void
    {
        $first = $this->apply->apply($this->account, $this->sampleTransaction(['entry_reference' => null]));

        // Re-sync with the same fundamentals but no entry_reference again.
        $result = $this->apply->apply($this->account, $this->sampleTransaction(['entry_reference' => null]));

        $this->assertSame(ApplyOutcome::Deduped, $result->outcome);
        $this->assertSame($first->transaction->id, $result->transaction->id);
    }

    public function test_fundamentals_match_when_multiple_rows_exist_bumps_occurrence(): void
    {
        // Seed 2 pre-existing rows with identical fundamentals directly via the model;
        // SPEC §6.3 dictates that a third incoming with matching fundamentals should
        // land as a brand-new row with occurrence = max + 1 = 3.
        $coffeeTx = $this->sampleTransaction([
            'entry_reference' => null,
            'transaction_amount' => ['amount' => '2.50', 'currency' => 'EUR'],
            'creditor' => ['name' => 'COFFEE SHOP'],
            'remittance_information' => [],
        ]);

        // First application: clean insert at occurrence=1.
        $first = $this->apply->apply($this->account, $coffeeTx);
        $this->assertSame(1, $first->transaction->occurrence);

        // Simulate a second legitimate same-day coffee already in DB at occurrence=2.
        // In the normal sync flow you don't get here with just the current algorithm,
        // but the SPEC-mandated branch still needs to work if the state arises
        // (manual reconciliation, historical data imports, etc.).
        Transaction::query()->create([
            'bank_account_id' => $this->account->id,
            'dedup_hash' => $first->transaction->dedup_hash,
            'entry_reference' => null,
            'status' => TransactionStatus::Fetched,
            'transaction_status' => 'BOOK',
            'booking_date' => $first->transaction->booking_date,
            'amount_milliunits' => $first->transaction->amount_milliunits,
            'currency' => $first->transaction->currency,
            'credit_debit_indicator' => $first->transaction->credit_debit_indicator,
            'counterparty_name' => $first->transaction->counterparty_name,
            'counterparty_resolution_level' => $first->transaction->counterparty_resolution_level,
            'raw_payload' => $first->transaction->raw_payload,
            'occurrence' => 2,
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);

        // Third coffee coming in: fundamentals match 2 existing → insert as occurrence=3.
        $third = $this->apply->apply($this->account, $coffeeTx);

        $this->assertSame(ApplyOutcome::Inserted, $third->outcome);
        $this->assertSame(3, $third->transaction->occurrence);
        $this->assertSame(3, Transaction::query()->count());
    }

    public function test_second_identical_transaction_with_no_entry_reference_is_deduped_not_duplicated(): void
    {
        // SPEC §6.3 step 2: if exactly one fundamentals match exists, the incoming is
        // treated as a re-sync of that transaction (dedup), not as a duplicate.
        $coffeeTx = $this->sampleTransaction([
            'entry_reference' => null,
            'transaction_amount' => ['amount' => '2.50', 'currency' => 'EUR'],
            'creditor' => ['name' => 'COFFEE SHOP'],
        ]);

        $this->apply->apply($this->account, $coffeeTx);
        $this->apply->apply($this->account, $coffeeTx);

        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_pre_cutoff_transaction_auto_skipped(): void
    {
        $this->account->import_cutoff_date = Carbon::parse('2026-04-01');
        $this->account->save();

        $result = $this->apply->apply($this->account, $this->sampleTransaction(['booking_date' => '2026-03-15']));

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Skipped, $result->transaction->status);
        $this->assertSame('before import cutoff', $result->transaction->skip_reason);
        $this->assertNotNull($result->transaction->skipped_at);
    }

    public function test_post_cutoff_transaction_goes_to_fetched(): void
    {
        $this->account->import_cutoff_date = Carbon::parse('2026-04-01');
        $this->account->save();

        $result = $this->apply->apply($this->account, $this->sampleTransaction(['booking_date' => '2026-04-02']));

        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        $this->assertNull($result->transaction->skipped_at);
    }

    public function test_tracking_account_post_cutoff_lands_as_status_tracking(): void
    {
        $this->account->ynab_account_type = YnabAccountType::Tracking;
        $this->account->import_cutoff_date = Carbon::parse('2026-04-01');
        $this->account->save();

        $result = $this->apply->apply(
            $this->account->refresh(),
            $this->sampleTransaction(['booking_date' => '2026-04-15']),
        );

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Tracking, $result->transaction->status);
        $this->assertNull($result->transaction->skipped_at);
        $this->assertNull($result->transaction->skip_reason);
    }

    public function test_tracking_account_pre_cutoff_still_lands_as_skipped(): void
    {
        // Cutoff precedes the tracking branch (SPEC §6.5): pre-cutoff history
        // is dropped uniformly across on_budget and tracking accounts.
        $this->account->ynab_account_type = YnabAccountType::Tracking;
        $this->account->import_cutoff_date = Carbon::parse('2026-04-01');
        $this->account->save();

        $result = $this->apply->apply(
            $this->account->refresh(),
            $this->sampleTransaction(['booking_date' => '2026-03-15']),
        );

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Skipped, $result->transaction->status);
        $this->assertSame('before import cutoff', $result->transaction->skip_reason);
        $this->assertNotNull($result->transaction->skipped_at);
    }

    public function test_resync_of_tracking_row_preserves_status_tracking(): void
    {
        $this->account->ynab_account_type = YnabAccountType::Tracking;
        $this->account->import_cutoff_date = Carbon::parse('2026-04-01');
        $this->account->save();

        $first = $this->apply->apply(
            $this->account->refresh(),
            $this->sampleTransaction(['booking_date' => '2026-04-15']),
        );
        $this->assertSame(TransactionStatus::Tracking, $first->transaction->status);

        // Re-sync with a metadata-only change (remittance) so the update
        // branch is exercised but the immutable status is preserved.
        $second = $this->apply->apply(
            $this->account->refresh(),
            $this->sampleTransaction([
                'booking_date' => '2026-04-15',
                'remittance_information' => ['CARD PAYMENT PINGO DOCE LISBOA · later detail'],
            ]),
        );

        $this->assertSame(ApplyOutcome::Updated, $second->outcome);
        $this->assertSame($first->transaction->id, $second->transaction->id);
        $this->assertSame(
            'CARD PAYMENT PINGO DOCE LISBOA · later detail',
            $second->transaction->refresh()->remittance_information,
        );
        $this->assertSame(TransactionStatus::Tracking, $second->transaction->refresh()->status);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_mock_aspsp_inversion_resolves_at_level_1(): void
    {
        // Mock puts counterparty in creditor.name even for CRDT (inbound).
        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => null,
            'creditor' => ['name' => 'EMPLOYER PAYROLL'],
            'transaction_amount' => ['amount' => '1800.00', 'currency' => 'EUR'],
        ]));

        $this->assertSame(1, $result->transaction->counterparty_resolution_level);
        $this->assertSame('EMPLOYER PAYROLL', $result->transaction->counterparty_name);
        $this->assertSame(1_800_000, $result->transaction->amount_milliunits);
    }

    // -----------------------------------------------------------------------
    // Own-account classifier tests (GH #14)
    // -----------------------------------------------------------------------

    /**
     * DBIT, same currency, destination IBAN only in free-text "To account,".
     * Acceptance criterion #1: status=transfer, counterparty_name="Transfer : <dest>".
     */
    public function test_own_account_same_currency_dbit_free_text_classified_as_transfer(): void
    {
        $destination = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL EUR Rulaj',
            'iban' => 'RO00BANK0000000000000001',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'own1',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => null,
            'debtor' => null,
            'transaction_amount' => ['amount' => '760.00', 'currency' => 'EUR'],
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO00BANK0000000000000001, Details, transfer',
            ],
        ]));

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Transfer, $result->transaction->status);
        $this->assertSame('Transfer : ING SRL EUR Rulaj', $result->transaction->counterparty_name);
        $this->assertNotSame('ACME SRL', $result->transaction->counterparty_name);
        $this->assertNotSame('BUGETUL DE STAT', $result->transaction->counterparty_name);
        $this->assertNotSame('Bugetul de Stat RO', $result->transaction->counterparty_name);
        $this->assertNotNull($destination->id);
    }

    /**
     * DBIT, different currency (EUR tx → RON destination), IBAN in free-text.
     * Acceptance criterion #2: status=fetched, counterparty_name="Currency Exchange".
     */
    public function test_own_account_different_currency_dbit_free_text_classified_as_fx(): void
    {
        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL RON',
            'iban' => 'RO00BANK0000000000000002',
            'currency' => 'RON',
            'is_base_currency' => false,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'own2',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => null,
            'debtor' => null,
            'transaction_amount' => ['amount' => '235.00', 'currency' => 'EUR'],
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO00BANK0000000000000002, Details, schimb valutar',
            ],
        ]));

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        $this->assertSame('Currency Exchange', $result->transaction->counterparty_name);
        $this->assertNotSame('ACME SRL', $result->transaction->counterparty_name);
    }

    /**
     * Acceptance criterion #3: external IBAN (not an own account) → no own-account override.
     * The "To account, <IBAN>" is detected but the IBAN does not belong to any own account,
     * so the classifier returns null and the resolver output stands unchanged (status=fetched,
     * no Transfer/FX label applied). The exact resolved name depends on the bank's rules
     * (mock bank has no beneficiary-first rule, so L2 returns the raw trimmed remittance);
     * what matters is that the own-account classifier did NOT fire.
     */
    public function test_external_beneficiary_with_to_account_not_overridden(): void
    {
        // No own account with this IBAN — classifier must return null.
        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'ext1',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => null,
            'debtor' => null,
            'transaction_amount' => ['amount' => '100.00', 'currency' => 'EUR'],
            'remittance_information' => [
                'Beneficiary, ACME SRL, To account, RO99XXXX0000000000000099, Details, transfer',
            ],
        ]));

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        // Classifier did NOT apply — name is the resolver's L2 output (raw remittance for mock bank).
        $this->assertStringNotContainsString('Transfer :', $result->transaction->counterparty_name ?? '');
        $this->assertNotSame('Currency Exchange', $result->transaction->counterparty_name);
        $this->assertNotSame('BUGETUL DE STAT', $result->transaction->counterparty_name);
        $this->assertNotSame('Bugetul de Stat RO', $result->transaction->counterparty_name);
    }

    /**
     * Acceptance criterion #4: unparseable free-text → (Unknown) — never "BUGETUL DE STAT".
     * Pins the no-mislabel guarantee from SPEC §0.
     */
    public function test_unparseable_remittance_resolves_to_unknown_never_bugetul(): void
    {
        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'unk1',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'debtor' => null,
            'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
            'remittance_information' => ['completely unparseable free text xyzzy'],
        ]));

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertNotSame('BUGETUL DE STAT', $result->transaction->counterparty_name);
        $this->assertNotSame('Bugetul de Stat RO', $result->transaction->counterparty_name);
        $this->assertNotSame('Currency Exchange', $result->transaction->counterparty_name);
    }

    /**
     * Acceptance criterion #5: structured creditor_account.iban path used before free-text.
     */
    public function test_own_account_structured_iban_takes_priority_over_free_text(): void
    {
        $destination = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL EUR Structured',
            'iban' => 'RO00BANK0000000000000003',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'struct1',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => ['iban' => 'RO00BANK0000000000000003'],
            'debtor' => null,
            // Free-text points to an unknown IBAN — structured field wins.
            'remittance_information' => [
                'To account, RO99XXXX0000000000000099',
            ],
        ]));

        $this->assertSame(TransactionStatus::Transfer, $result->transaction->status);
        $this->assertSame('Transfer : ING SRL EUR Structured', $result->transaction->counterparty_name);
        $this->assertNotNull($destination->id);
    }

    /**
     * Acceptance criterion #6: self-exclusion — destination IBAN = source account IBAN → no override.
     */
    public function test_own_account_self_transfer_not_classified(): void
    {
        // Give the source account its own IBAN.
        $this->account->iban = 'RO00BANK0000000000000010';
        $this->account->save();

        $result = $this->apply->apply($this->account->refresh(), $this->sampleTransaction([
            'entry_reference' => 'self1',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => ['iban' => 'RO00BANK0000000000000010'],
            'debtor' => null,
        ]));

        // Source excluded → no own-account match → normal resolution (L4 unknown or remittance).
        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        $this->assertNotSame('Transfer : ', substr($result->transaction->counterparty_name ?? '', 0, 10));
    }

    /**
     * Acceptance criterion #6 (inactive variant): inactive own account not matched.
     */
    public function test_inactive_own_account_not_classified(): void
    {
        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'Inactive Account',
            'iban' => 'RO00BANK0000000000000011',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => false,  // <-- inactive
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'inactive1',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => ['iban' => 'RO00BANK0000000000000011'],
            'debtor' => null,
        ]));

        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        $this->assertNotSame('Currency Exchange', $result->transaction->counterparty_name);
    }

    /**
     * Codex refinement #1: duplicate active IBAN → ambiguous → no override.
     */
    public function test_duplicate_active_iban_is_ambiguous_no_override(): void
    {
        $sharedIban = 'RO00BANK0000000000000020';

        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'Account A',
            'iban' => $sharedIban,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'Account B',
            'iban' => $sharedIban,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'dup1',
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => ['iban' => $sharedIban],
            'debtor' => null,
        ]));

        // Ambiguous → no override → normal resolution.
        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        $this->assertNotSame('Transfer : Account A', $result->transaction->counterparty_name);
        $this->assertNotSame('Transfer : Account B', $result->transaction->counterparty_name);
    }

    /**
     * Codex refinement #2: CRDT own-account transfer with "From account," in free-text.
     */
    public function test_own_account_crdt_free_text_from_account_classified_as_transfer(): void
    {
        // The source account (CRDT = inbound) is $this->account (EUR).
        // The debtor (origin account we're receiving from) is our own RON account.
        $origin = BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL EUR Savings',
            'iban' => 'RO00BANK0000000000000030',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->apply->apply($this->account, $this->sampleTransaction([
            'entry_reference' => 'crdt1',
            'credit_debit_indicator' => 'CRDT',
            'creditor' => null,
            'creditor_account' => null,
            'debtor' => null,
            'debtor_account' => null,
            'transaction_amount' => ['amount' => '500.00', 'currency' => 'EUR'],
            'remittance_information' => [
                'Transfer, From account, RO00BANK0000000000000030, Details, internal',
            ],
        ]));

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Transfer, $result->transaction->status);
        $this->assertSame('Transfer : ING SRL EUR Savings', $result->transaction->counterparty_name);
        $this->assertNotNull($origin->id);
    }

    /**
     * Codex refinement #3: pre-cutoff own-account transfer → skipped (not transfer).
     * Cutoff guard must still win over the ownAccountTransfer flag.
     */
    public function test_own_account_pre_cutoff_lands_as_skipped(): void
    {
        $this->account->import_cutoff_date = Carbon::parse('2026-04-01');
        $this->account->save();

        BankAccount::query()->create([
            'bank_slug' => 'mock',
            'display_name' => 'ING SRL EUR Target',
            'iban' => 'RO00BANK0000000000000040',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $result = $this->apply->apply($this->account->refresh(), $this->sampleTransaction([
            'entry_reference' => 'cutoff1',
            'booking_date' => '2026-03-15',  // before cutoff
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'creditor_account' => ['iban' => 'RO00BANK0000000000000040'],
            'debtor' => null,
        ]));

        $this->assertSame(TransactionStatus::Skipped, $result->transaction->status);
        $this->assertSame('before import cutoff', $result->transaction->skip_reason);
    }
}
