<?php

namespace Tests\Unit\Services\Counterparty;

use App\Services\Counterparty\Resolver;
use PHPUnit\Framework\TestCase;

class ResolverTest extends TestCase
{
    public function test_level_0_direction_correct_crdt_picks_debtor(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => ['name' => 'ACME Payments'],
            'creditor' => ['name' => 'Me (wrong side)'],
        ]);

        $this->assertSame('ACME Payments', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_direction_correct_dbit_picks_creditor(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'PINGO DOCE AREEIRO'],
            'debtor' => ['name' => null],
        ]);

        $this->assertSame('PINGO DOCE AREEIRO', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_1_inverted_fallback_mock_aspsp_style(): void
    {
        // Mock ASPSP puts the counterparty in creditor.name for an incoming CRDT.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => null,
            'creditor' => ['name' => 'Employer Payroll'],
        ]);

        $this->assertSame('Employer Payroll', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_level_2_remittance_fallback_strips_banking_prefix_and_truncates(): void
    {
        $long = 'CARD PAYMENT '.str_repeat('X', 200);
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'debtor' => null,
            'remittance_information' => [$long],
        ]);

        $this->assertSame(2, $resolved->level);
        $this->assertSame(64, mb_strlen($resolved->name));
        $this->assertStringStartsNotWith('CARD PAYMENT', $resolved->name);
    }

    public function test_level_3_additional_information_fallback(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'additional_information' => 'Bank Fee',
        ]);

        $this->assertSame('Bank Fee', $resolved->name);
        $this->assertSame(3, $resolved->level);
    }

    public function test_level_4_unknown_is_final_fallback(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
        ]);

        $this->assertSame('(Unknown)', $resolved->name);
        $this->assertSame(4, $resolved->level);
    }

    public function test_level_2_strips_bcp_compra_card_number_prefix(): void
    {
        // BCP card-purchase remittance starts with "COMPRA NNNN " where
        // NNNN is the last-4 of the card or a category code (9800, 5962).
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['COMPRA 9800 Vinted Vilnius LT'],
        ]);

        $this->assertSame('Vinted Vilnius LT', $resolved->name);
        $this->assertSame(2, $resolved->level);
    }

    public function test_level_2_strips_bcp_compra_with_5962_prefix(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['COMPRA 5962 CONTINENTE LISBOA PT'],
        ]);

        $this->assertSame('CONTINENTE LISBOA PT', $resolved->name);
    }

    public function test_level_2_strips_bcp_compra_and_contactless_suffix(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['COMPRA 9800 MACAS DE ADAO LISBOA PT CONTACTLESS'],
        ]);

        $this->assertSame('MACAS DE ADAO LISBOA PT', $resolved->name);
    }

    public function test_level_2_strips_bcp_trf_de(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'CRDT',
            'remittance_information' => ['TRF DE Apparte - Emergency fund'],
        ]);

        $this->assertSame('Apparte - Emergency fund', $resolved->name);
    }

    public function test_level_2_strips_bcp_trf_mb_way_double_space(): void
    {
        // Observed double-space after the "P" in real BCP remittance.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['TRF MB WAY P  SONAM MALLA'],
        ]);

        $this->assertSame('SONAM MALLA', $resolved->name);
    }

    public function test_level_2_strips_bcp_trf_p_o(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['TRF. P O NIKOLAY SAVCHENKO'],
        ]);

        $this->assertSame('NIKOLAY SAVCHENKO', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_prefix(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD EDP COMERCIAL  16'],
        ]);

        $this->assertSame('EDP COMERCIAL  16', $resolved->name);
    }

    public function test_level_2_extracts_merchant_from_ing_structured_remittance(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['Card number, **** 0429, Transaction at, GITHUB, INC.  US  GITHUB.COM, Authorization date, 24-04-2026, Authorization number, 071280, Amount, 4,00  USD, Settlement amount, 3,43 EUR'],
        ]);

        $this->assertSame('GITHUB, INC.  US  GITHUB.COM', $resolved->name);
        $this->assertSame(2, $resolved->level);
    }

    public function test_level_2_ing_structured_without_authorization_date_uses_eol(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['Card number, **** 0429, Transaction at, ELEVENLABS.IO  US  ELEVENLABS.IO'],
        ]);

        $this->assertSame('ELEVENLABS.IO  US  ELEVENLABS.IO', $resolved->name);
    }

    public function test_level_2_falls_through_to_plain_remittance_when_unstructured(): void
    {
        // Revolut shape: short, no banking prefix.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['www.kiwi.com*BRNO'],
        ]);

        $this->assertSame('www.kiwi.com*BRNO', $resolved->name);
        $this->assertSame(2, $resolved->level);
    }

    public function test_normalize_lowercases_strips_non_alphanumerics_and_collapses_whitespace(): void
    {
        $this->assertSame('pingo doce areeiro', Resolver::normalize('PINGO-DOCE Areeiro!!'));
        $this->assertSame('', Resolver::normalize(''));
        $this->assertSame('', Resolver::normalize(null));
        $this->assertSame('acme 123', Resolver::normalize('  ACME / 123  '));
    }
}
