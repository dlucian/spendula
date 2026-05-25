<?php

namespace Tests\Feature\Commands\Spendula;

use App\Enums\CreditDebitIndicator;
use App\Enums\TransactionStatus;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\PayeeRule;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PendingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_fetched_transactions_in_json(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        $fetched = [];
        for ($i = 0; $i < 5; $i++) {
            $fetched[] = $this->seedTransaction($account, entryRef: 'ref-fetched-'.$i)->id;
        }

        $this->seedTransaction($account, entryRef: 'ref-approved-1', status: TransactionStatus::Approved);
        $this->seedTransaction($account, entryRef: 'ref-approved-2', status: TransactionStatus::Approved);

        Artisan::call('spendula:pending', ['--json' => true]);
        $output = Artisan::output();

        $doc = json_decode(trim($output), true);
        $this->assertIsArray($doc);
        $this->assertSame(5, $doc['count']);
        $this->assertCount(5, $doc['transactions']);

        $returnedIds = array_column($doc['transactions'], 'id');
        foreach ($fetched as $id) {
            $this->assertContains($id, $returnedIds);
        }
    }

    public function test_json_schema_matches_contract(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        $tx = $this->seedTransaction($account, entryRef: 'ref-schema');
        $tx->counterparty_name = 'ACME Corp';
        $tx->remittance_information = 'Invoice 123';
        $tx->save();

        Artisan::call('spendula:pending', ['--json' => true]);
        $output = Artisan::output();

        $doc = json_decode(trim($output), true);
        $this->assertSame(1, $doc['count']);
        $row = $doc['transactions'][0];

        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('bank_slug', $row);
        $this->assertArrayHasKey('bank_account_id', $row);
        $this->assertArrayHasKey('bank_account_label', $row);
        $this->assertArrayHasKey('currency', $row);
        $this->assertArrayHasKey('booking_date', $row);
        $this->assertArrayHasKey('amount', $row);
        $this->assertArrayHasKey('amount_milliunits', $row);
        $this->assertArrayHasKey('counterparty_name', $row);
        $this->assertArrayHasKey('counterparty_resolution_level', $row);
        $this->assertArrayHasKey('remittance_information', $row);
        $this->assertArrayHasKey('entry_ref', $row);
        $this->assertArrayHasKey('occurrence', $row);

        $this->assertIsInt($row['amount_milliunits']);
        $this->assertIsInt($row['counterparty_resolution_level']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2,}$/', $row['amount']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['booking_date']);
        $this->assertSame('mock', $row['bank_slug']);
    }

    public function test_empty_queue_json(): void
    {
        $this->seedMockBank();

        Artisan::call('spendula:pending', ['--json' => true]);
        $output = trim(Artisan::output());

        $this->assertSame('{"count":0,"transactions":[]}', $output);
        $this->assertSame(0, Artisan::call('spendula:pending', ['--json' => true]));
    }

    public function test_empty_queue_table(): void
    {
        $this->seedMockBank();

        $this->artisan('spendula:pending')
            ->expectsOutputToContain('Nothing pending.')
            ->assertSuccessful();
    }

    public function test_bank_filter(): void
    {
        $this->seedMockBank();

        Bank::query()->create([
            'slug' => 'other',
            'display_name' => 'Other Bank',
            'aspsp_name' => 'Other ASPSP',
            'aspsp_country' => 'FI',
            'psu_type' => 'personal',
            'default_currency' => 'EUR',
            'sync_lookback_days' => 90,
            'active' => true,
        ]);

        $mockAccount = $this->seedAccount('EUR', 'mock');
        $otherAccount = $this->seedAccount('EUR', 'other');

        $mockTx = $this->seedTransaction($mockAccount, entryRef: 'ref-mock');
        $this->seedTransaction($otherAccount, entryRef: 'ref-other');

        Artisan::call('spendula:pending', ['--json' => true, '--bank' => 'mock']);
        $doc = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $doc['count']);
        $this->assertSame($mockTx->id, $doc['transactions'][0]['id']);
    }

    public function test_limit_caps_results(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        for ($i = 0; $i < 5; $i++) {
            $this->seedTransaction($account, entryRef: 'ref-limit-'.$i);
        }

        Artisan::call('spendula:pending', ['--json' => true, '--limit' => '2']);
        $doc = json_decode(trim(Artisan::output()), true);

        $this->assertSame(2, $doc['count']);
        $this->assertCount(2, $doc['transactions']);
    }

    public function test_invalid_limit_fails(): void
    {
        $this->seedMockBank();

        $this->artisan('spendula:pending', ['--limit' => '0'])
            ->expectsOutputToContain('--limit must be a positive integer.')
            ->assertFailed();

        $this->artisan('spendula:pending', ['--limit' => 'abc'])
            ->expectsOutputToContain('--limit must be a positive integer.')
            ->assertFailed();
    }

    public function test_read_only_does_not_mutate_state(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        $tx = $this->seedTransaction($account, entryRef: 'ref-readonly');
        $tx->counterparty_name = 'Coffee Shop';
        $tx->save();

        PayeeRule::query()->create([
            'bank_slug' => 'mock',
            'counterparty_name' => 'Coffee Shop',
            'action' => TransactionStatus::Skipped->value,
            'skip_reason' => 'unwanted',
        ]);

        Artisan::call('spendula:pending', ['--json' => true]);

        $this->assertSame(TransactionStatus::Fetched, $tx->refresh()->status);
    }

    public function test_amount_sign_carried_by_milliunits_only(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        $tx = $this->seedTransaction($account, entryRef: 'ref-debit');
        $tx->amount_milliunits = -34570;
        $tx->save();

        Artisan::call('spendula:pending', ['--json' => true]);
        $doc = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $doc['count']);
        $row = $doc['transactions'][0];
        $this->assertSame('34.57', $row['amount']);
        $this->assertSame(-34570, $row['amount_milliunits']);
    }

    public function test_formatter_tags_in_fields_preserved_verbatim(): void
    {
        $this->seedMockBank();
        $account = $this->seedAccount('EUR');

        $tx = $this->seedTransaction($account, entryRef: 'ref-tags');
        $tx->counterparty_name = '<info>ACME</info>';
        $tx->remittance_information = '<error>oops</error>';
        $tx->save();

        Artisan::call('spendula:pending', ['--json' => true]);
        $output = Artisan::output();

        $doc = json_decode(trim($output), true);
        $this->assertNotNull($doc, 'JSON must parse: '.$output);

        $row = $doc['transactions'][0];
        $this->assertSame('<info>ACME</info>', $row['counterparty_name']);
        $this->assertSame('<error>oops</error>', $row['remittance_information']);
    }

    private function seedMockBank(): void
    {
        if (Bank::query()->where('slug', 'mock')->exists()) {
            return;
        }

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

    private function seedAccount(string $currency, string $bankSlug = 'mock'): BankAccount
    {
        return BankAccount::query()->create([
            'bank_slug' => $bankSlug,
            'currency' => $currency,
            'is_base_currency' => strtoupper($currency) === 'EUR',
            'active' => true,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    private function seedTransaction(
        BankAccount $account,
        string $entryRef,
        TransactionStatus $status = TransactionStatus::Fetched,
    ): Transaction {
        static $seq = 0;
        $seq++;

        return Transaction::query()->create([
            'bank_account_id' => $account->id,
            'dedup_hash' => str_pad((string) $seq, 32, 'x'),
            'entry_reference' => $entryRef,
            'status' => $status,
            'transaction_status' => 'BOOK',
            'booking_date' => '2026-04-15',
            'amount_milliunits' => -1000 * $seq,
            'currency' => $account->currency,
            'credit_debit_indicator' => CreditDebitIndicator::Debit,
            'counterparty_name' => 'Coffee Shop',
            'counterparty_resolution_level' => 1,
            'raw_payload' => [],
            'first_seen_at' => Carbon::now(),
            'last_updated_from_bank_at' => Carbon::now(),
        ]);
    }
}
