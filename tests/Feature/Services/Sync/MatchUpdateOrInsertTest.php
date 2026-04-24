<?php

namespace Tests\Feature\Services\Sync;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Counterparty\Resolver;
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

        $this->apply = new MatchUpdateOrInsert(new Resolver);
    }

    /** @return array<string, mixed> */
    private function sampleTransaction(array $overrides = []): array
    {
        return array_replace_recursive([
            'entry_reference' => 'uxr2h',
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'value_date' => '2026-04-15',
            'transaction_amount' => ['amount' => '34.57', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'PINGO DOCE AREEIRO'],
            'debtor' => null,
            'remittance_information' => ['CARD PAYMENT PINGO DOCE AREEIRO'],
        ], $overrides);
    }

    public function test_inserts_new_transaction_with_status_fetched(): void
    {
        $result = $this->apply->apply($this->account, $this->sampleTransaction());

        $this->assertSame(ApplyOutcome::Inserted, $result->outcome);
        $this->assertSame(TransactionStatus::Fetched, $result->transaction->status);
        $this->assertSame(-34570, $result->transaction->amount_milliunits);
        $this->assertSame(CreditDebitIndicator::Debit, $result->transaction->credit_debit_indicator);
        $this->assertSame('PINGO DOCE AREEIRO', $result->transaction->counterparty_name);
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
            'remittance_information' => ['CARD PAYMENT PINGO DOCE AREEIRO · extra detail'],
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
}
