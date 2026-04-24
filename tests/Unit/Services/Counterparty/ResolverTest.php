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

    public function test_normalize_lowercases_strips_non_alphanumerics_and_collapses_whitespace(): void
    {
        $this->assertSame('pingo doce areeiro', Resolver::normalize('PINGO-DOCE Areeiro!!'));
        $this->assertSame('', Resolver::normalize(''));
        $this->assertSame('', Resolver::normalize(null));
        $this->assertSame('acme 123', Resolver::normalize('  ACME / 123  '));
    }
}
