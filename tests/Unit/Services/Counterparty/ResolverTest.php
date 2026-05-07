<?php

namespace Tests\Unit\Services\Counterparty;

use App\Services\Counterparty\Resolver;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use PHPUnit\Framework\TestCase;

class ResolverTest extends TestCase
{
    private Resolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the available/ dir directly so tests exercise every shipped
        // rule regardless of whether the operator has it enabled in the
        // local enabled/ symlinks.
        $availableDir = __DIR__.'/../../../../config/counterparty-rules-available';
        $this->resolver = new Resolver(
            new RuleLoader($availableDir),
            new RuleEngine,
        );
    }

    public function test_level_0_direction_correct_crdt_picks_debtor(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => ['name' => 'ACME Payments'],
            'creditor' => ['name' => 'Me (wrong side)'],
        ]);

        $this->assertSame('ACME Payments', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_direction_correct_dbit_picks_creditor(): void
    {
        $resolved = $this->resolver->resolve([
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
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => null,
            'creditor' => ['name' => 'Employer Payroll'],
        ]);

        $this->assertSame('Employer Payroll', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_level_2_returns_truncated_remittance_when_no_rule_matches(): void
    {
        $long = str_repeat('X', 200);
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'debtor' => null,
            'remittance_information' => [$long],
        ]);

        $this->assertSame(2, $resolved->level);
        $this->assertSame(64, mb_strlen($resolved->name));
    }

    public function test_level_3_additional_information_fallback(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'additional_information' => 'Bank Fee',
        ]);

        $this->assertSame('Bank Fee', $resolved->name);
        $this->assertSame(3, $resolved->level);
    }

    public function test_level_4_unknown_is_final_fallback(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
        ]);

        $this->assertSame('(Unknown)', $resolved->name);
        $this->assertSame(4, $resolved->level);
    }

    public function test_level_2_strips_bcp_compra_card_number_prefix(): void
    {
        // BCP card-purchase remittance starts with "COMPRA NNNN " where
        // NNNN is the last-4 of the card or a category code (9800, 5962).
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['COMPRA 9800 Vinted Vilnius LT'],
        ], 'bcp');

        $this->assertSame('Vinted Vilnius LT', $resolved->name);
        $this->assertSame(2, $resolved->level);
    }

    public function test_level_2_strips_bcp_compra_with_5962_prefix(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['COMPRA 5962 CONTINENTE LISBOA PT'],
        ], 'bcp');

        $this->assertSame('CONTINENTE LISBOA PT', $resolved->name);
    }

    public function test_level_2_strips_bcp_compra_and_contactless_suffix(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['COMPRA 9800 MACAS DE ADAO LISBOA PT CONTACTLESS'],
        ], 'bcp');

        $this->assertSame('MACAS DE ADAO LISBOA PT', $resolved->name);
    }

    public function test_level_2_strips_bcp_trf_de(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'CRDT',
            'remittance_information' => ['TRF DE Apparte - Emergency fund'],
        ], 'bcp');

        $this->assertSame('Apparte - Emergency fund', $resolved->name);
    }

    public function test_level_2_strips_bcp_trf_mb_way_double_space(): void
    {
        // Observed double-space after the "P" in real BCP remittance.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['TRF MB WAY P  SONAM MALLA'],
        ], 'bcp');

        $this->assertSame('SONAM MALLA', $resolved->name);
    }

    public function test_level_2_strips_bcp_trf_p_o(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['TRF. P O NIKOLAY SAVCHENKO'],
        ], 'bcp');

        $this->assertSame('NIKOLAY SAVCHENKO', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_prefix(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD EDP COMERCIAL  16'],
        ], 'bcp');

        $this->assertSame('EDP COMERCIAL  16', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_trailing_pt_reference_for_gin_clube(): void
    {
        // BCP direct debit: merchant name, then customer reference, then PT
        // creditor identifier. Without trimming, every DD becomes a unique
        // payee in YNAB and aggregation breaks.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD GIN CLUBE PORT 00335110554    PT22100415'],
        ], 'bcp');

        $this->assertSame('GIN CLUBE PORT', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_trailing_reference_for_nos(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD NOS Comunicaco 06258979526    PT20100839'],
        ], 'bcp');

        $this->assertSame('NOS Comunicaco', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_trailing_reference_with_di_prefix(): void
    {
        // Some Portuguese creditors use a DI-prefixed identifier instead of
        // PT-prefixed. Same shape: digits-then-creditor-id is noise.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD OCIDENTAL 00346849108 MEDIS       DI72078874'],
        ], 'bcp');

        $this->assertSame('OCIDENTAL', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_with_accented_intermediate_token(): void
    {
        // Portuguese sub-product names commonly carry diacritics
        // (MÉDIS, SAÚDE, etc.). The intermediate-word slot must accept
        // Unicode letters or these descriptors fall through and the
        // reference + creditor id stay in the payee.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD OCIDENTAL 00346849108 MÉDIS       DI72078874'],
        ], 'bcp');

        $this->assertSame('OCIDENTAL', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_trailing_reference_for_edp_with_hyphen(): void
    {
        // Trailing hyphen on the merchant name is a BCP cosmetic artifact —
        // strip it after the cut so the payee matches the no-hyphen variant.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD EDP COMERCIAL- 16010014044135 PT34100781'],
        ], 'bcp');

        $this->assertSame('EDP COMERCIAL', $resolved->name);
    }

    public function test_level_2_dd_without_creditor_id_suffix_preserves_full_descriptor(): void
    {
        // Without a trailing PT##.../DI##... creditor id we can't safely
        // assume the 4+ digit run is a customer reference, so fall through
        // to the generic DD-prefix-only strip and preserve the descriptor
        // (rather than mis-trimming "DD ACME 2024" to "ACME").
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 2024'],
        ], 'bcp');

        $this->assertSame('ACME 2024', $resolved->name);
    }

    public function test_level_2_dd_with_digits_but_no_creditor_id_keeps_trailing_word(): void
    {
        // "DD GYM 1234 PREMIUM" — no PT/DI suffix, so the 1234 might be a
        // tier code rather than a reference. Don't truncate.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD GYM 1234 PREMIUM'],
        ], 'bcp');

        $this->assertSame('GYM 1234 PREMIUM', $resolved->name);
    }

    public function test_level_2_dd_merchant_with_embedded_digits_drops_pt_tail(): void
    {
        // dd-with-reference rejects this (merchant token must be non-digit),
        // so it falls into dd-short-reference which strips the PT/DI
        // authorization tail and leaves the rest of the descriptor.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD GYM 1234 PREMIUM 000123 PT12345678'],
        ], 'bcp');

        $this->assertSame('GYM 1234 PREMIUM 000123', $resolved->name);
    }

    public function test_level_2_dd_with_intermediate_word_and_short_digits_drops_pt_tail(): void
    {
        // "DD ACME 2024 PLAN PT12345678" — dd-with-reference's 8+ digit
        // ref threshold isn't met (4 digits), so dd-short-reference takes
        // it and drops just the PT tail.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 2024 PLAN PT12345678'],
        ], 'bcp');

        $this->assertSame('ACME 2024 PLAN', $resolved->name);
    }

    public function test_level_2_dd_with_long_ref_then_numeric_intermediate_drops_pt_tail(): void
    {
        // "DD ACME 12345678 2024 PT12345678" — dd-with-reference's
        // optional sub-product slot is alpha (\p{L}+), so the numeric
        // "2024" disqualifies it. dd-short-reference takes it next.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 12345678 2024 PT12345678'],
        ], 'bcp');

        $this->assertSame('ACME 12345678 2024', $resolved->name);
    }

    public function test_level_2_dd_with_short_ref_immediately_before_creditor_id_drops_pt_tail(): void
    {
        // dd-with-reference requires an 8+ digit ref; this has only 4
        // before PT/DI. dd-short-reference takes it and drops the tail.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 2024 PT12345678'],
        ], 'bcp');

        $this->assertSame('ACME 2024', $resolved->name);
    }

    public function test_level_2_dd_sunsetfitgym_with_short_ref_drops_pt_tail(): void
    {
        // Real BCP data with a 4-digit ref. dd-short-reference matches
        // (8+ digit threshold not met) and the `collapse` post-hook
        // squashes the BCP-padded internal whitespace into single spaces.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD SUNSETFITGYM   2010           PT81118656'],
        ], 'bcp');

        $this->assertSame('SUNSETFITGYM 2010', $resolved->name);
    }

    public function test_level_2_strips_bcp_pag_bxval_prefix_for_viaverde(): void
    {
        // BCP toll/parking via Via Verde tag: "PAG BXVAL- NNNN VIAVERDE".
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['PAG BXVAL- 5962 VIAVERDE'],
        ], 'bcp');

        $this->assertSame('VIAVERDE', $resolved->name);
    }

    public function test_level_2_normalizes_bcp_lev_atm_with_location(): void
    {
        // BCP ATM withdrawal: "LEV ATM <card4> <atm-id>   <location>        <cardholder>".
        // Cardholder name is BCP echoing the account holder back — drop it.
        // Keep location for per-city payee aggregation in YNAB.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['LEV ATM 5962 703   LISBOA        Mario Nunes E'],
        ], 'bcp');

        $this->assertSame('ATM LISBOA', $resolved->name);
    }

    public function test_level_2_normalizes_bcp_lev_atm_with_multi_word_location(): void
    {
        // Location can contain doubled internal spaces in some BCP forms
        // (multi-word place names with stray padding). The cardholder
        // gap is much wider (8 spaces in observed data) than any internal
        // location spacing, so the regex uses a wider boundary to
        // distinguish them.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['LEV ATM 5962 703   VILA  NOVA        Mario Nunes E'],
        ], 'bcp');

        $this->assertSame('ATM VILA NOVA', $resolved->name);
    }

    public function test_level_2_normalizes_bcp_lev_atm_without_location_to_bare_atm(): void
    {
        // Defensive fallback: if the LEV ATM line doesn't fit the expected
        // shape, still collapse to a stable "ATM" payee instead of leaving
        // the whole noisy line through.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['LEV ATM 5962'],
        ], 'bcp');

        $this->assertSame('ATM', $resolved->name);
    }

    public function test_level_2_extracts_merchant_from_ing_structured_remittance(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['Card number, **** 0429, Transaction at, GITHUB, INC.  US  GITHUB.COM, Authorization date, 24-04-2026, Authorization number, 071280, Amount, 4,00  USD, Settlement amount, 3,43 EUR'],
        ], 'ing-ro-business');

        $this->assertSame('GITHUB, INC.  US  GITHUB.COM', $resolved->name);
        $this->assertSame(2, $resolved->level);
    }

    public function test_level_2_ing_structured_without_authorization_date_uses_eol(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['Card number, **** 0429, Transaction at, ELEVENLABS.IO  US  ELEVENLABS.IO'],
        ], 'ing-ro-business');

        $this->assertSame('ELEVENLABS.IO  US  ELEVENLABS.IO', $resolved->name);
    }

    public function test_level_2_falls_through_to_plain_remittance_when_unstructured(): void
    {
        // Revolut shape: short, no banking prefix.
        $resolved = $this->resolver->resolve([
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
