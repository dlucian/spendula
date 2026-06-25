<?php

namespace Tests\Feature\Services\Push;

use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Transaction;
use App\Services\Push\PayloadBuilder;
use Tests\TestCase;

class PayloadBuilderTest extends TestCase
{
    private PayloadBuilder $builder;

    private BankAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PayloadBuilder();
        $this->account = BankAccount::make(['ynab_account_id' => 'test-ynab-account-id']);
    }

    /** @param array<string, mixed> $attrs */
    private function makeTransaction(array $attrs): Transaction
    {
        return Transaction::make(array_merge([
            'bank_account_id' => 1,
            'ynab_import_id' => null,
            'entry_reference' => 'ref-test',
            'status' => TransactionStatus::Transfer,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -3450,
            'currency' => 'EUR',
            'counterparty_name' => null,
            'remittance_information' => null,
            'occurrence' => 1,
            'raw_payload' => [
                'credit_debit_indicator' => 'DBIT',
                'creditor' => ['name' => 'Counterparty'],
                'debtor' => null,
            ],
        ], $attrs));
    }

    public function test_transfer_prefix_is_stripped(): void
    {
        $tx = $this->makeTransaction(['counterparty_name' => 'Transfer : ING SRL EUR Deposit']);
        $result = $this->builder->build($tx, $this->account);
        $this->assertSame('ING SRL EUR Deposit', $result['payee_name']);
    }

    public function test_transfer_prefix_with_whitespace_only_suffix_falls_back_to_generic(): void
    {
        $tx = $this->makeTransaction(['counterparty_name' => 'Transfer : ']);
        $result = $this->builder->build($tx, $this->account);
        $this->assertSame('Own account transfer', $result['payee_name']);
    }

    public function test_starting_balance_falls_back_to_generic(): void
    {
        $tx = $this->makeTransaction([
            'counterparty_name' => 'Starting Balance',
            'status' => TransactionStatus::Approved,
        ]);
        $result = $this->builder->build($tx, $this->account);
        $this->assertSame('Own account transfer', $result['payee_name']);
    }

    public function test_manual_balance_adjustment_falls_back_to_generic(): void
    {
        $tx = $this->makeTransaction([
            'counterparty_name' => 'Manual Balance Adjustment',
            'status' => TransactionStatus::Approved,
        ]);
        $result = $this->builder->build($tx, $this->account);
        $this->assertSame('Own account transfer', $result['payee_name']);
    }

    public function test_reconciliation_balance_adjustment_falls_back_to_generic(): void
    {
        $tx = $this->makeTransaction([
            'counterparty_name' => 'Reconciliation Balance Adjustment',
            'status' => TransactionStatus::Approved,
        ]);
        $result = $this->builder->build($tx, $this->account);
        $this->assertSame('Own account transfer', $result['payee_name']);
    }

    public function test_normal_payee_passes_through_unchanged(): void
    {
        $tx = $this->makeTransaction([
            'counterparty_name' => 'Pingo Doce',
            'status' => TransactionStatus::Approved,
        ]);
        $result = $this->builder->build($tx, $this->account);
        $this->assertSame('Pingo Doce', $result['payee_name']);
    }

    public function test_null_payee_stays_null(): void
    {
        $tx = $this->makeTransaction([
            'counterparty_name' => null,
            'status' => TransactionStatus::Approved,
        ]);
        $result = $this->builder->build($tx, $this->account);
        $this->assertNull($result['payee_name']);
    }

    public function test_truncation_applies_after_sanitising_transfer_prefix(): void
    {
        // PAYEE_MAX is 50; suffix here is 55 chars, so it should be truncated to 50.
        $longSuffix = str_repeat('A', 55);
        $tx = $this->makeTransaction(['counterparty_name' => 'Transfer : '.$longSuffix]);
        $result = $this->builder->build($tx, $this->account);
        $this->assertSame(str_repeat('A', 50), $result['payee_name']);
    }

    public function test_transfer_row_still_gets_transfer_prefix_in_memo(): void
    {
        $tx = $this->makeTransaction(['counterparty_name' => 'Transfer : ING SRL EUR Deposit']);
        $result = $this->builder->build($tx, $this->account);
        $this->assertStringStartsWith('[TRANSFER] ', (string) $result['memo']);
        $this->assertSame('ING SRL EUR Deposit', $result['payee_name']);
    }
}
