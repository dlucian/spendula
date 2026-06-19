<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sync;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Services\Sync\TopupLink;
use App\Services\Sync\TopupLinkLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * TopupLinkLoader unit tests.
 *
 * Tests the JSON parsing, validation, and ref-resolution pipeline without
 * exercising CrossSourceTransferLinker. The DB-backed resolution tests use
 * RefreshDatabase so bank_accounts are isolated per test.
 */
class TopupLinkLoaderTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/spendula-topup-test-'.uniqid();
        mkdir($this->tempDir, 0755, true);

        // Seed the parent Bank rows required by bank_accounts.bank_slug FK.
        foreach (['bcp', 'revolut'] as $slug) {
            Bank::query()->create([
                'slug' => $slug,
                'display_name' => ucfirst($slug),
                'aspsp_name' => ucfirst($slug),
                'aspsp_country' => 'PT',
                'psu_type' => 'personal',
                'default_currency' => 'EUR',
                'sync_lookback_days' => 90,
                'active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tempDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = "{$dir}/{$f}";
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
        rmdir($dir);
    }

    /** @param  mixed[]  $data */
    private function writeConfig(array $data): void
    {
        file_put_contents(
            "{$this->tempDir}/own-account-topups.json",
            json_encode($data),
        );
    }

    private function seedAccount(
        string $bankSlug,
        string $displayName,
        string $iban,
        bool $active = true,
    ): BankAccount {
        return BankAccount::query()->create([
            'bank_slug' => $bankSlug,
            'display_name' => $displayName,
            'iban' => $iban,
            'currency' => 'EUR',
            'is_base_currency' => true,
            'active' => $active,
            'first_linked_at' => Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // File-not-found → empty list

    public function test_missing_file_returns_empty_links(): void
    {
        $loader = new TopupLinkLoader($this->tempDir);

        $this->assertSame([], $loader->links());
    }

    // -------------------------------------------------------------------------
    // JSON parsing errors

    public function test_malformed_json_throws(): void
    {
        file_put_contents("{$this->tempDir}/own-account-topups.json", '{ not json');
        $loader = new TopupLinkLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Malformed JSON/');

        $loader->links();
    }

    public function test_missing_links_key_returns_empty(): void
    {
        $this->writeConfig(['description' => 'no links key']);
        $loader = new TopupLinkLoader($this->tempDir);

        $this->assertSame([], $loader->links());
    }

    public function test_empty_links_array_returns_empty(): void
    {
        $this->writeConfig(['links' => []]);
        $loader = new TopupLinkLoader($this->tempDir);

        $this->assertSame([], $loader->links());
    }

    // -------------------------------------------------------------------------
    // Validation errors

    public function test_missing_required_field_throws(): void
    {
        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                // missing funding_card_last4, funding_marker, etc.
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required field/i');

        $loader->links();
    }

    public function test_empty_string_field_throws(): void
    {
        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => '',  // empty string
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut EUR',
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => 3,
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-empty string/i');

        $loader->links();
    }

    public function test_negative_tolerance_days_throws(): void
    {
        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut EUR',
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => -1,
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-negative integer/i');

        $loader->links();
    }

    public function test_apple_pay_tokens_must_be_array(): void
    {
        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut EUR',
                'apple_pay_tokens' => '2798',  // string not array
                'amount_tolerance_days' => 3,
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/array/i');

        $loader->links();
    }

    // -------------------------------------------------------------------------
    // Successful resolution by display_name

    public function test_resolves_destination_by_display_name(): void
    {
        $account = $this->seedAccount('revolut', 'Revolut EUR', 'LT00REVO0000000000001');

        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut EUR',
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => 3,
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);
        $links = $loader->links();

        $this->assertCount(1, $links);
        $this->assertInstanceOf(TopupLink::class, $links[0]);
        $this->assertSame($account->id, $links[0]->resolvedDestinationId);
        $this->assertSame('5962', $links[0]->fundingCardLast4);
        $this->assertSame(['2798'], $links[0]->applePayTokens);
        $this->assertSame(3, $links[0]->amountToleranceDays);
    }

    // -------------------------------------------------------------------------
    // Successful resolution by IBAN

    public function test_resolves_destination_by_iban_when_no_display_name_match(): void
    {
        $account = $this->seedAccount('revolut', 'Revolut Account', 'LT00REVO0000000000002');

        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'LT00REVO0000000000002',  // IBAN as ref
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => 2,
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);
        $links = $loader->links();

        $this->assertCount(1, $links);
        $this->assertSame($account->id, $links[0]->resolvedDestinationId);
    }

    // -------------------------------------------------------------------------
    // Unknown ref → null resolvedDestinationId (logged, not thrown)

    public function test_unknown_ref_yields_null_resolved_id(): void
    {
        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Does Not Exist',
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => 3,
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);
        $links = $loader->links();

        $this->assertCount(1, $links);
        $this->assertNull($links[0]->resolvedDestinationId);
    }

    // -------------------------------------------------------------------------
    // Inactive account not resolved

    public function test_inactive_account_not_resolved(): void
    {
        $this->seedAccount('revolut', 'Revolut EUR', 'LT00REVO0000000000003', active: false);

        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut EUR',
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => 3,
            ],
        ]]);
        $loader = new TopupLinkLoader($this->tempDir);
        $links = $loader->links();

        $this->assertCount(1, $links);
        $this->assertNull($links[0]->resolvedDestinationId);
    }

    // -------------------------------------------------------------------------
    // Caching: second call returns same result without re-querying

    public function test_links_are_cached_after_first_call(): void
    {
        $account = $this->seedAccount('revolut', 'Revolut EUR', 'LT00REVO0000000000004');

        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut EUR',
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => 3,
            ],
        ]]);

        $loader = new TopupLinkLoader($this->tempDir);
        $first = $loader->links();

        // Overwrite file with empty — cached call should still return the first result.
        $this->writeConfig(['links' => []]);
        $second = $loader->links();

        $this->assertSame(count($first), count($second));
        $this->assertCount(1, $second);
        $this->assertSame($account->id, $second[0]->resolvedDestinationId);
    }

    // -------------------------------------------------------------------------
    // Multiple links

    public function test_multiple_links_resolved_independently(): void
    {
        $account1 = $this->seedAccount('revolut', 'Revolut EUR', 'LT00REVO0000000000005');
        $account2 = $this->seedAccount('revolut', 'Revolut USD', 'LT00REVO0000000000006');

        $this->writeConfig(['links' => [
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '5962',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut EUR',
                'apple_pay_tokens' => ['2798'],
                'amount_tolerance_days' => 3,
            ],
            [
                'funding_bank_slug' => 'bcp',
                'funding_card_last4' => '9800',
                'funding_marker' => 'Revolut',
                'destination_account_ref' => 'Revolut USD',
                'apple_pay_tokens' => ['4321'],
                'amount_tolerance_days' => 2,
            ],
        ]]);

        $loader = new TopupLinkLoader($this->tempDir);
        $links = $loader->links();

        $this->assertCount(2, $links);
        $this->assertSame($account1->id, $links[0]->resolvedDestinationId);
        $this->assertSame($account2->id, $links[1]->resolvedDestinationId);
        $this->assertSame('5962', $links[0]->fundingCardLast4);
        $this->assertSame('9800', $links[1]->fundingCardLast4);
    }
}
