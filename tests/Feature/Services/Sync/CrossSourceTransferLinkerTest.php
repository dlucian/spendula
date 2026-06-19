<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sync;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Sync\CrossSourceTransferLinker;
use App\Services\Sync\TopupLink;
use App\Services\Sync\TopupLinkLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * CrossSourceTransferLinker feature tests.
 *
 * Verifies the full cross-source top-up dedup logic against a real DB.
 * Tests cover all acceptance criteria from GH #16:
 *
 *  - Funding leg synced first, destination arrives later → pair linked.
 *  - Destination leg synced first, funding arrives later → pair linked.
 *  - Both synced in same call (e.g. same sync batch) → linked on second call.
 *  - Idempotent: re-syncing an already-linked pair is a no-op.
 *  - Destination already pushed → funding promoted, destination left alone (late_pair).
 *  - No matching link config → no linking.
 *  - Outside settlement window → no linking.
 *  - Wrong amount → no linking.
 */
class CrossSourceTransferLinkerTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $fundingAccount;

    private BankAccount $destinationAccount;

    private TopupLink $link;

    /** @var MockInterface&TopupLinkLoader */
    private TopupLinkLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        Bank::query()->create([
            'slug' => 'bcp',
            'display_name' => 'Millennium BCP',
            'aspsp_name' => 'Millennium BCP',
            'aspsp_country' => 'PT',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        Bank::query()->create([
            'slug' => 'revolut',
            'display_name' => 'Revolut LT',
            'aspsp_name' => 'Revolut LT',
            'aspsp_country' => 'LT',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $this->fundingAccount = BankAccount::query()->create([
            'bank_slug' => 'bcp',
            'display_name' => 'BCP Current',
            'iban' => 'PT00BCP0000000000000001',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $this->destinationAccount = BankAccount::query()->create([
            'bank_slug' => 'revolut',
            'display_name' => 'Revolut EUR',
            'iban' => 'LT00REVO0000000000001',
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        $this->link = new TopupLink(
            fundingBankSlug: 'bcp',
            fundingCardLast4: '5962',
            fundingMarker: 'Revolut',
            destinationAccountRef: 'Revolut EUR',
            applePayTokens: ['2798'],
            amountToleranceDays: 3,
            resolvedDestinationId: $this->destinationAccount->id,
        );

        $this->loader = Mockery::mock(TopupLinkLoader::class);
        $this->loader->allows('links')->andReturn([$this->link]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers

    private function makeLinker(): CrossSourceTransferLinker
    {
        return new CrossSourceTransferLinker($this->loader);
    }

    private function seedFundingTx(
        string $date = '2026-06-10',
        int $amountMilliunits = -600_000,
        TransactionStatus $status = TransactionStatus::Fetched,
    ): Transaction {
        return Transaction::query()->create([
            'bank_account_id' => $this->fundingAccount->id,
            'dedup_hash' => substr(md5(Str::random(16)), 0, 32),
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => $date,
            'amount_milliunits' => $amountMilliunits,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => 'COMPRA 5962 Revolut 2180 Dublin IE',
            'counterparty_resolution_level' => 2,
            'remittance_information' => 'COMPRA 5962 Revolut 2180 Dublin IE',
            'raw_payload' => [
                'credit_debit_indicator' => 'DBIT',
                'creditor' => ['name' => 'COMPRA 5962 Revolut 2180 Dublin IE'],
                'debtor' => null,
                'remittance_information' => ['COMPRA 5962 Revolut 2180 Dublin IE'],
            ],
            'occurrence' => 1,
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }

    private function seedDestinationTx(
        string $date = '2026-06-10',
        int $amountMilliunits = 600_000,
        TransactionStatus $status = TransactionStatus::Fetched,
    ): Transaction {
        return Transaction::query()->create([
            'bank_account_id' => $this->destinationAccount->id,
            'dedup_hash' => substr(md5(Str::random(16)), 0, 32),
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => $date,
            'amount_milliunits' => $amountMilliunits,
            'currency' => 'EUR',
            'credit_debit_indicator' => CreditDebitIndicator::Credit,
            'counterparty_name' => 'Apple Pay Top-Up',
            'counterparty_resolution_level' => 2,
            'remittance_information' => 'Apple Pay Top-Up by *2798',
            'raw_payload' => [
                'credit_debit_indicator' => 'CRDT',
                'creditor' => null,
                'debtor' => ['name' => 'Apple Pay'],
                'remittance_information' => ['Apple Pay Top-Up by *2798'],
            ],
            'occurrence' => 1,
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Scenario 1: Funding leg arrives first; destination arrives later.

    public function test_funding_leg_first_then_destination_links_pair(): void
    {
        $funding = $this->seedFundingTx();

        // Funding arrives first — no destination counterpart yet, no link.
        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: 'COMPRA 5962 Revolut 2180 Dublin IE',
        );

        $funding->refresh();
        $this->assertSame(TransactionStatus::Fetched, $funding->status);
        $this->assertNull($funding->linked_transfer_id);

        // Destination arrives.
        $destination = $this->seedDestinationTx();
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Transfer, $funding->status);
        $this->assertSame(TransactionStatus::TransferDropped, $destination->status);
        $this->assertSame($destination->id, $funding->linked_transfer_id);
        $this->assertSame($funding->id, $destination->linked_transfer_id);
        $this->assertStringContainsString('Transfer', $funding->counterparty_name ?? '');
        $this->assertStringContainsString('Revolut EUR', $funding->counterparty_name ?? '');
    }

    // -------------------------------------------------------------------------
    // Scenario 2: Destination leg arrives first; funding arrives later.

    public function test_destination_leg_first_then_funding_links_pair(): void
    {
        // Destination arrives first — no funding counterpart yet.
        $destination = $this->seedDestinationTx();
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $destination->refresh();
        $this->assertSame(TransactionStatus::Fetched, $destination->status);
        $this->assertNull($destination->linked_transfer_id);

        // Funding arrives.
        $funding = $this->seedFundingTx();
        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: 'COMPRA 5962 Revolut 2180 Dublin IE',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Transfer, $funding->status);
        $this->assertSame(TransactionStatus::TransferDropped, $destination->status);
        $this->assertSame($destination->id, $funding->linked_transfer_id);
        $this->assertSame($funding->id, $destination->linked_transfer_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 3: Idempotent — re-linking an already-linked pair is a no-op.

    public function test_idempotent_resync_does_not_change_linked_pair(): void
    {
        $funding = $this->seedFundingTx();
        $destination = $this->seedDestinationTx();

        // Link via funding-side call.
        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: 'COMPRA 5962 Revolut 2180 Dublin IE',
        );
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Transfer, $funding->status);
        $this->assertSame(TransactionStatus::TransferDropped, $destination->status);

        // Re-sync: call link() on the already-linked funding tx again.
        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: 'COMPRA 5962 Revolut 2180 Dublin IE',
        );

        $funding->refresh();
        $destination->refresh();

        // Nothing should have changed.
        $this->assertSame(TransactionStatus::Transfer, $funding->status);
        $this->assertSame(TransactionStatus::TransferDropped, $destination->status);
        $this->assertSame($destination->id, $funding->linked_transfer_id);
        $this->assertSame($funding->id, $destination->linked_transfer_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 4: Destination already pushed → funding promoted, destination untouched.

    public function test_destination_already_pushed_promotes_funding_but_leaves_destination(): void
    {
        $destination = $this->seedDestinationTx(status: TransactionStatus::Pushed);

        $funding = $this->seedFundingTx();
        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: 'COMPRA 5962 Revolut 2180 Dublin IE',
        );

        $funding->refresh();
        $destination->refresh();

        // Funding promoted to transfer.
        $this->assertSame(TransactionStatus::Transfer, $funding->status);
        $this->assertSame($destination->id, $funding->linked_transfer_id);

        // Destination left as-is (still pushed, not transfer_dropped).
        $this->assertSame(TransactionStatus::Pushed, $destination->status);
        // Destination's linked_transfer_id is NOT set — the late_pair guard fires before the drop.
        $this->assertNull($destination->linked_transfer_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 5: No matching config → no link.

    public function test_no_matching_link_config_no_link(): void
    {
        // Loader returns empty list.
        $emptyLoader = Mockery::mock(TopupLinkLoader::class);
        $emptyLoader->allows('links')->andReturn([]);
        $linker = new CrossSourceTransferLinker($emptyLoader);

        $funding = $this->seedFundingTx();
        $destination = $this->seedDestinationTx();

        $linker->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: 'COMPRA 5962 Revolut 2180 Dublin IE',
        );
        $linker->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Fetched, $funding->status);
        $this->assertSame(TransactionStatus::Fetched, $destination->status);
        $this->assertNull($funding->linked_transfer_id);
        $this->assertNull($destination->linked_transfer_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 6: Outside settlement window → no link.

    public function test_outside_settlement_window_no_link(): void
    {
        $funding = $this->seedFundingTx(date: '2026-06-01');
        // Destination is 5 days later, window is 3 — should not link.
        $destination = $this->seedDestinationTx(date: '2026-06-06');

        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: null,
        );
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Fetched, $funding->status);
        $this->assertSame(TransactionStatus::Fetched, $destination->status);
        $this->assertNull($funding->linked_transfer_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 7: Wrong amount → no link.

    public function test_wrong_amount_no_link(): void
    {
        $funding = $this->seedFundingTx(amountMilliunits: -600_000);
        $destination = $this->seedDestinationTx(amountMilliunits: 500_000);  // different amount

        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: null,
        );
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Fetched, $funding->status);
        $this->assertNull($funding->linked_transfer_id);
    }

    // -------------------------------------------------------------------------
    // Scenario 8: Funding descriptor does not match (wrong card or no marker).

    public function test_funding_descriptor_mismatch_no_link(): void
    {
        // "COMPRA 9999 Amazon" — card 9999 not in the link, no "Revolut" marker.
        $funding = $this->seedFundingTx();
        $funding->raw_payload = [
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'COMPRA 9999 Amazon Dublin IE'],
            'debtor' => null,
        ];
        $funding->save();

        $destination = $this->seedDestinationTx();

        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 9999 Amazon Dublin IE',
            remittanceInfo: null,
        );
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Fetched, $funding->status);
        $this->assertSame(TransactionStatus::Fetched, $destination->status);
    }

    // -------------------------------------------------------------------------
    // Scenario 9: Destination remittance does not match apple_pay_token.

    public function test_destination_unknown_token_no_link(): void
    {
        $funding = $this->seedFundingTx();
        $destination = $this->seedDestinationTx();
        // Override remittance to use an unknown token.
        $destination->remittance_information = 'Apple Pay Top-Up by *9999';
        $destination->save();

        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: null,
        );
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *9999',  // not in config
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Fetched, $funding->status);
        $this->assertSame(TransactionStatus::Fetched, $destination->status);
    }

    // -------------------------------------------------------------------------
    // Scenario 10: Settlement window edge — within window (exactly ±3 days).

    public function test_within_settlement_window_links(): void
    {
        // Funding 3 days before destination — exactly at the window boundary.
        $funding = $this->seedFundingTx(date: '2026-06-07');
        $destination = $this->seedDestinationTx(date: '2026-06-10');

        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: null,
        );
        $this->makeLinker()->link(
            $this->destinationAccount,
            $destination,
            rawCounterparty: 'Apple Pay',
            remittanceInfo: 'Apple Pay Top-Up by *2798',
        );

        $funding->refresh();
        $destination->refresh();

        $this->assertSame(TransactionStatus::Transfer, $funding->status);
        $this->assertSame(TransactionStatus::TransferDropped, $destination->status);
    }

    // -------------------------------------------------------------------------
    // Scenario 11: Skipped / tracking transactions are ignored by linker.

    public function test_skipped_transaction_ignored_by_linker(): void
    {
        $funding = $this->seedFundingTx(status: TransactionStatus::Skipped);
        $destination = $this->seedDestinationTx();

        $this->makeLinker()->link(
            $this->fundingAccount,
            $funding,
            rawCounterparty: 'COMPRA 5962 Revolut 2180 Dublin IE',
            remittanceInfo: null,
        );

        $funding->refresh();
        $this->assertSame(TransactionStatus::Skipped, $funding->status);
        $this->assertNull($funding->linked_transfer_id);
    }
}
