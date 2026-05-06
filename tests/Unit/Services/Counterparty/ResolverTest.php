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

    public function test_level_2_strips_bcp_dd_trailing_pt_reference_for_gin_clube(): void
    {
        // BCP direct debit: merchant name, then customer reference, then PT
        // creditor identifier. Without trimming, every DD becomes a unique
        // payee in YNAB and aggregation breaks.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD GIN CLUBE PORT 00335110554    PT22100415'],
        ]);

        $this->assertSame('GIN CLUBE PORT', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_trailing_reference_for_nos(): void
    {
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD NOS Comunicaco 06258979526    PT20100839'],
        ]);

        $this->assertSame('NOS Comunicaco', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_trailing_reference_with_di_prefix(): void
    {
        // Some Portuguese creditors use a DI-prefixed identifier instead of
        // PT-prefixed. Same shape: digits-then-creditor-id is noise.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD OCIDENTAL 00346849108 MEDIS       DI72078874'],
        ]);

        $this->assertSame('OCIDENTAL', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_with_accented_intermediate_token(): void
    {
        // Portuguese sub-product names commonly carry diacritics
        // (MÉDIS, SAÚDE, etc.). The intermediate-word slot must accept
        // Unicode letters or these descriptors fall through and the
        // reference + creditor id stay in the payee.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD OCIDENTAL 00346849108 MÉDIS       DI72078874'],
        ]);

        $this->assertSame('OCIDENTAL', $resolved->name);
    }

    public function test_level_2_strips_bcp_dd_trailing_reference_for_edp_with_hyphen(): void
    {
        // Trailing hyphen on the merchant name is a BCP cosmetic artifact —
        // strip it after the cut so the payee matches the no-hyphen variant.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD EDP COMERCIAL- 16010014044135 PT34100781'],
        ]);

        $this->assertSame('EDP COMERCIAL', $resolved->name);
    }

    public function test_level_2_dd_without_creditor_id_suffix_preserves_full_descriptor(): void
    {
        // Without a trailing PT##.../DI##... creditor id we can't safely
        // assume the 4+ digit run is a customer reference, so fall through
        // to the generic DD-prefix-only strip and preserve the descriptor
        // (rather than mis-trimming "DD ACME 2024" to "ACME").
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 2024'],
        ]);

        $this->assertSame('ACME 2024', $resolved->name);
    }

    public function test_level_2_dd_with_digits_but_no_creditor_id_keeps_trailing_word(): void
    {
        // "DD GYM 1234 PREMIUM" — no PT/DI suffix, so the 1234 might be a
        // tier code rather than a reference. Don't truncate.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD GYM 1234 PREMIUM'],
        ]);

        $this->assertSame('GYM 1234 PREMIUM', $resolved->name);
    }

    public function test_level_2_dd_merchant_with_embedded_digits_falls_through(): void
    {
        // When the merchant descriptor itself contains a 4+ digit token
        // (e.g. tier code, year), the regex can't tell "real customer
        // reference + creditor id" from "merchant-with-digits + creditor
        // id". Conservative: fall through to plain DD prefix-strip so the
        // full descriptor reaches YNAB instead of being mis-cut.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD GYM 1234 PREMIUM 000123 PT12345678'],
        ]);

        $this->assertSame('GYM 1234 PREMIUM 000123 PT12345678', $resolved->name);
    }

    public function test_level_2_dd_with_intermediate_word_and_short_digits_falls_through(): void
    {
        // "DD ACME 2024 PLAN PT12345678" — only 4 digits before an
        // intermediate alpha word, so we can't prove "2024 PLAN" is a
        // customer reference rather than part of the merchant descriptor.
        // Falls through to plain prefix strip.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 2024 PLAN PT12345678'],
        ]);

        $this->assertSame('ACME 2024 PLAN PT12345678', $resolved->name);
    }

    public function test_level_2_dd_with_long_ref_then_numeric_intermediate_falls_through(): void
    {
        // "DD ACME 12345678 2024 PT12345678" — the intermediate token (2024)
        // is itself numeric, so the variant-2 sub-product slot is ambiguous
        // (could be year/plan code rather than an alpha sub-product like
        // MEDIS). Restricting the intermediate to alphabetic text keeps
        // this line from mis-cutting to "ACME".
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 12345678 2024 PT12345678'],
        ]);

        $this->assertSame('ACME 12345678 2024 PT12345678', $resolved->name);
    }

    public function test_level_2_dd_with_short_ref_immediately_before_creditor_id_falls_through(): void
    {
        // 4-digit ref directly before PT/DI is structurally
        // indistinguishable from a merchant whose name ends with a year
        // (e.g. "DD AMAZON 2024 PT12345678"). Falls through to plain DD
        // prefix-strip rather than risk over-merging distinct payees.
        // Real BCP rows with a 4-digit ref (e.g. SUNSETFITGYM with ref
        // "2010") are accepted as collateral here — only 2 such rows in
        // observed data, and a noisy payee is strictly safer than
        // mis-merging.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD ACME 2024 PT12345678'],
        ]);

        $this->assertSame('ACME 2024 PT12345678', $resolved->name);
    }

    public function test_level_2_dd_sunsetfitgym_with_short_ref_falls_through(): void
    {
        // Documents the SUNSETFITGYM trade-off: real BCP data with a
        // 4-digit ref now falls through (was: cleaned to "SUNSETFITGYM"
        // in earlier iterations). Loss accepted in favour of the
        // tighter false-positive guard against year-suffixed merchants.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['DD SUNSETFITGYM   2010           PT81118656'],
        ]);

        $this->assertSame('SUNSETFITGYM   2010           PT81118656', $resolved->name);
    }

    public function test_level_2_strips_bcp_pag_bxval_prefix_for_viaverde(): void
    {
        // BCP toll/parking via Via Verde tag: "PAG BXVAL- NNNN VIAVERDE".
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['PAG BXVAL- 5962 VIAVERDE'],
        ]);

        $this->assertSame('VIAVERDE', $resolved->name);
    }

    public function test_level_2_normalizes_bcp_lev_atm_with_location(): void
    {
        // BCP ATM withdrawal: "LEV ATM <card4> <atm-id>   <location>        <cardholder>".
        // Cardholder name is BCP echoing the account holder back — drop it.
        // Keep location for per-city payee aggregation in YNAB.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['LEV ATM 5962 703   LISBOA        Mario Nunes E'],
        ]);

        $this->assertSame('ATM LISBOA', $resolved->name);
    }

    public function test_level_2_normalizes_bcp_lev_atm_with_multi_word_location(): void
    {
        // Location can contain doubled internal spaces in some BCP forms
        // (multi-word place names with stray padding). The cardholder
        // gap is much wider (8 spaces in observed data) than any internal
        // location spacing, so the regex uses a wider boundary to
        // distinguish them.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['LEV ATM 5962 703   VILA  NOVA        Mario Nunes E'],
        ]);

        $this->assertSame('ATM VILA NOVA', $resolved->name);
    }

    public function test_level_2_normalizes_bcp_lev_atm_without_location_to_bare_atm(): void
    {
        // Defensive fallback: if the LEV ATM line doesn't fit the expected
        // shape, still collapse to a stable "ATM" payee instead of leaving
        // the whole noisy line through.
        $resolved = (new Resolver)->resolve([
            'credit_debit_indicator' => 'DBIT',
            'remittance_information' => ['LEV ATM 5962'],
        ]);

        $this->assertSame('ATM', $resolved->name);
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
