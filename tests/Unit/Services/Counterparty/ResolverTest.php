<?php

namespace Tests\Unit\Services\Counterparty;

use App\Services\Counterparty\Resolver;
use App\Services\Counterparty\Rules\Rule;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use App\Services\Counterparty\Rules\RuleValidationException;
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
            'creditor' => ['name' => 'PINGO DOCE LISBOA'],
            'debtor' => ['name' => null],
        ]);

        $this->assertSame('PINGO DOCE LISBOA', $resolved->name);
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

    // -----------------------------------------------------------------------
    // No-mislabel regression (Major 3)
    // The Resolver must NEVER rewrite a real external beneficiary into an
    // unrelated real entity (e.g. "BUGETUL DE STAT").
    // -----------------------------------------------------------------------

    public function test_external_beneficiary_resolves_to_itself_not_unrelated_entity(): void
    {
        // SEPA-correct L0: creditor.name for a DBIT is returned verbatim.
        // No rule should rewrite a legitimate company name to a different entity.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'ACME SRL'],
            'debtor' => null,
        ]);

        $this->assertSame('ACME SRL', $resolved->name);
        $this->assertSame(0, $resolved->level);
        $this->assertNotSame('BUGETUL DE STAT', $resolved->name);
    }

    public function test_unparseable_remittance_resolves_to_own_text_not_unrelated_entity(): void
    {
        // When no structured counterparty is present, L2 returns the
        // trimmed remittance text itself — never a randomly-matched entity.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => null,
            'debtor' => null,
            'remittance_information' => ['OP 42 / diverse cheltuieli'],
        ]);

        $this->assertSame(2, $resolved->level);
        $this->assertSame('OP 42 / diverse cheltuieli', $resolved->name);
        $this->assertNotSame('BUGETUL DE STAT', $resolved->name);
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

    public function test_level_3_falls_back_to_bank_transaction_code_description(): void
    {
        // ING Romania fee/interest rows: empty remittance, no debtor/creditor,
        // no additional_information — but bank_transaction_code.description
        // carries the bank's posting category.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'bank_transaction_code' => ['description' => 'Service Fee'],
        ]);

        $this->assertSame('Service Fee', $resolved->name);
        $this->assertSame(3, $resolved->level);
    }

    public function test_level_3_btc_description_used_when_additional_information_is_blank(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'additional_information' => '   ',
            'bank_transaction_code' => ['description' => 'Interest adjustment'],
        ]);

        $this->assertSame('Interest adjustment', $resolved->name);
        $this->assertSame(3, $resolved->level);
    }

    public function test_level_3_additional_information_wins_over_btc_description(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'additional_information' => 'Bank Fee',
            'bank_transaction_code' => ['description' => 'Service Fee'],
        ]);

        $this->assertSame('Bank Fee', $resolved->name);
        $this->assertSame(3, $resolved->level);
    }

    public function test_level_3_btc_description_truncates_to_64_chars(): void
    {
        $long = str_repeat('Y', 200);
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'bank_transaction_code' => ['description' => $long],
        ]);

        $this->assertSame(3, $resolved->level);
        $this->assertSame(64, mb_strlen($resolved->name));
    }

    public function test_level_4_when_btc_description_missing_or_non_string(): void
    {
        // bank_transaction_code present but description is non-string.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'bank_transaction_code' => ['description' => null, 'code' => 'PMNT'],
        ]);
        $this->assertSame('(Unknown)', $resolved->name);
        $this->assertSame(4, $resolved->level);

        // bank_transaction_code present but description blank.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'bank_transaction_code' => ['description' => '   '],
        ]);
        $this->assertSame('(Unknown)', $resolved->name);
        $this->assertSame(4, $resolved->level);

        // bank_transaction_code is not an array.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'bank_transaction_code' => 'PMNT',
        ]);
        $this->assertSame('(Unknown)', $resolved->name);
        $this->assertSame(4, $resolved->level);
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
            'remittance_information' => ['DD EXAMPLEGYM   2010           PT99000001'],
        ], 'bcp');

        $this->assertSame('EXAMPLEGYM 2010', $resolved->name);
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

    public function test_level_0_applies_name_rules_to_rewrite_creditor_name(): void
    {
        // Revolut puts the dirty merchant string directly into creditor.name
        // for card payments. The bolt-eu-embedded-id name rule strips the
        // trailing booking ID so all four shapes collapse to "Bolt.eu".
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'Bolt.euo1234567890'],
        ], 'revolut');

        $this->assertSame('Bolt.eu', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_name_rule_handles_slashed_bolt_variant(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'Bolt.eu/o/5544332211'],
        ], 'revolut');

        $this->assertSame('Bolt.eu', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_returns_unchanged_name_when_no_name_rule_matches(): void
    {
        // A clean Revolut merchant string that no name rule should touch.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'Lufthansa AG'],
        ], 'revolut');

        $this->assertSame('Lufthansa AG', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_skips_name_rules_when_bank_slug_is_null(): void
    {
        // Same dirty string, no bank slug — current pre-name-rule behavior
        // must be preserved (no rules consulted, raw name returned).
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'Bolt.euo1234567890'],
        ]);

        $this->assertSame('Bolt.euo1234567890', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_preserves_whitespace_for_null_bank_slug(): void
    {
        // Pre-name-rule contract: with no bank slug, the raw L0 name is
        // returned verbatim — including surrounding whitespace. Trimming
        // would be a behaviour change unrelated to name_rules.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => '  ACME  '],
        ]);

        $this->assertSame('  ACME  ', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_preserves_whitespace_when_no_name_rule_matches(): void
    {
        // Same contract for the case where the bank *has* name_rules but
        // none of them match: applyOrNull returns null, the raw name is
        // returned verbatim — no implicit trim, no truncation.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => '  Lufthansa AG  '],
        ], 'revolut');

        $this->assertSame('  Lufthansa AG  ', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_does_not_truncate_long_clean_name_when_no_rule_matches(): void
    {
        // L0/L1 truncation to 64 only applies when a name_rule actually
        // rewrote the input. A 100-char clean name from a bank with
        // name_rules should be returned untruncated.
        $longName = str_repeat('A', 100);
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => $longName],
        ], 'revolut');

        $this->assertSame($longName, $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_level_0_blanked_by_name_rule_falls_through_to_l1(): void
    {
        // Suppressive name_rule: if a rule matches and produces an empty
        // result after post-hooks, the L0 candidate is treated as
        // intentionally blanked — resolution falls through to L1, which
        // then yields its own value (or further down the ladder).
        $resolver = $this->resolverWithInlineNameRules('synthetic', [
            new Rule(
                name: 'suppress-direct-correct',
                description: 'Suppress L0 to force L1.',
                pattern: '/^SELF DIRECT$/',
                replacement: '',
                postHooks: [],
                fixtures: [],
            ),
        ]);

        $resolved = $resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'SELF DIRECT'],
            'debtor' => ['name' => 'L1 FALLBACK'],
        ], 'synthetic');

        $this->assertSame('L1 FALLBACK', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_level_1_blanked_by_name_rule_falls_through_to_l2(): void
    {
        // Same suppression contract at L1: a rule that intentionally
        // blanks the inverted candidate should redirect to L2.
        $resolver = $this->resolverWithInlineNameRules('synthetic', [
            new Rule(
                name: 'suppress-inverted',
                description: 'Suppress L1 to force L2.',
                pattern: '/^SELF INVERTED$/',
                replacement: '',
                postHooks: [],
                fixtures: [],
            ),
        ]);

        $resolved = $resolver->resolve([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => null,
            'creditor' => ['name' => 'SELF INVERTED'],
            'remittance_information' => ['ACME GMBH'],
        ], 'synthetic');

        $this->assertSame('ACME GMBH', $resolved->name);
        $this->assertSame(2, $resolved->level);
    }

    /**
     * Spin up a Resolver backed by a temp rule directory containing a
     * single bank file with the given name_rules. The loader is exercised
     * end-to-end (parse, validation, cache); only the per-test JSON shape
     * differs.
     *
     * @param  Rule[]  $nameRules
     */
    private function resolverWithInlineNameRules(string $bankSlug, array $nameRules): Resolver
    {
        $tempDir = sys_get_temp_dir().'/spendula-resolver-test-'.uniqid();
        mkdir($tempDir, 0755, true);
        register_shutdown_function(function () use ($tempDir) {
            foreach (glob("{$tempDir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tempDir);
        });

        $serializedNameRules = array_map(static fn (Rule $r): array => [
            'name' => $r->name,
            'description' => $r->description,
            'pattern' => $r->pattern,
            'replacement' => $r->replacement,
            'post' => $r->postHooks,
            // RuleLoader requires a non-empty 'tests' array but doesn't
            // run them against the engine — that's the self-test's job.
            // A trivial passing fixture is enough to satisfy the schema.
            'tests' => [['in' => 'noop', 'out' => 'noop']],
        ], $nameRules);

        file_put_contents(
            "{$tempDir}/{$bankSlug}.json",
            json_encode(['name' => $bankSlug, 'rules' => [], 'name_rules' => $serializedNameRules]),
        );

        return new Resolver(new RuleLoader($tempDir), new RuleEngine);
    }

    public function test_level_1_applies_name_rules_when_inverted(): void
    {
        // Mock-ASPSP-style inversion: counterparty appears in creditor.name
        // for an incoming CRDT. Name rules must still rewrite, level stays 1.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => null,
            'creditor' => ['name' => 'Bolt.euo1234567890'],
        ], 'revolut');

        $this->assertSame('Bolt.eu', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    // GH #42 — ATM cash withdrawal short-circuit. Universal across banks
    // (the trigger is ISO 20022 bank_transaction_code.code = "ATM"), runs
    // before the L0/L1 name lookup, returns the configured synthetic label
    // at level 1 regardless of debtor/creditor names or remittance text.

    public function test_atm_short_circuit_overrides_debtor_name_on_dbit(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'debtor' => ['name' => 'JANE DOE'],
            'creditor' => ['name' => null],
            'bank_transaction_code' => ['code' => 'ATM'],
        ], 'revolut');

        $this->assertSame('ATM Cash', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_atm_short_circuit_fires_when_both_names_are_null(): void
    {
        // Confirms the branch does not depend on a name candidate existing.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'debtor' => null,
            'creditor' => null,
            'bank_transaction_code' => ['code' => 'ATM'],
        ], 'revolut');

        $this->assertSame('ATM Cash', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_atm_short_circuit_ignores_remittance_information(): void
    {
        // Real Revolut LT shape: 'Cash at <street>'. Location extraction is
        // a deferred follow-up; the v1 contract is one stable label.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'debtor' => ['name' => 'JANE DOE'],
            'bank_transaction_code' => ['code' => 'ATM'],
            'remittance_information' => ['Cash at R Tomas Da Anunciacao,6'],
        ], 'revolut');

        $this->assertSame('ATM Cash', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_atm_short_circuit_is_case_insensitive_on_btc_code(): void
    {
        // Defensive: banks may emit lowercase 'atm' even though ISO 20022
        // says uppercase.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'debtor' => ['name' => 'JANE DOE'],
            'bank_transaction_code' => ['code' => 'atm'],
        ], 'revolut');

        $this->assertSame('ATM Cash', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_crdt_atm_falls_through_to_normal_ladder(): void
    {
        // Cash deposit at ATM (rare). Normal ladder: CRDT direction-correct
        // picks debtor.name at L0.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'CRDT',
            'debtor' => ['name' => 'Cash Deposit Source'],
            'creditor' => ['name' => 'JANE DOE'],
            'bank_transaction_code' => ['code' => 'ATM'],
        ]);

        $this->assertSame('Cash Deposit Source', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_dbit_with_non_atm_btc_code_falls_through(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'PINGO DOCE LISBOA'],
            'bank_transaction_code' => ['code' => 'CARD'],
        ]);

        $this->assertSame('PINGO DOCE LISBOA', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_dbit_with_missing_btc_falls_through(): void
    {
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'PINGO DOCE LISBOA'],
        ]);

        $this->assertSame('PINGO DOCE LISBOA', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_dbit_with_btc_code_non_string_falls_through(): void
    {
        // Defensive: bank emits the field but with a non-string value.
        $resolved = $this->resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'creditor' => ['name' => 'PINGO DOCE LISBOA'],
            'bank_transaction_code' => ['code' => 42],
        ]);

        $this->assertSame('PINGO DOCE LISBOA', $resolved->name);
        $this->assertSame(0, $resolved->level);
    }

    public function test_atm_label_is_configurable_via_constructor(): void
    {
        $availableDir = __DIR__.'/../../../../config/counterparty-rules-available';
        $resolver = new Resolver(
            new RuleLoader($availableDir),
            new RuleEngine,
            'Cash withdrawal',
        );

        $resolved = $resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'debtor' => ['name' => 'JANE DOE'],
            'bank_transaction_code' => ['code' => 'ATM'],
        ], 'revolut');

        $this->assertSame('Cash withdrawal', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_atm_short_circuit_still_validates_bank_rules(): void
    {
        // Codex review round 2 P2: the ATM short-circuit must NOT bypass
        // RuleLoader validation. A broken `<bank>.json` should fail-fast
        // on every bank-scoped resolve() call regardless of which
        // transaction shape happens to land first.
        $brokenDir = sys_get_temp_dir().'/spendula-broken-rules-'.uniqid();
        mkdir($brokenDir);
        file_put_contents(
            $brokenDir.'/revolut.json',
            json_encode([
                'name' => 'revolut',
                'rules' => [[
                    'name' => 'broken',
                    'description' => 'unparseable regex',
                    'pattern' => '/[broken/',
                    'replacement' => 'x',
                    'tests' => [['in' => 'a', 'out' => 'b']],
                ]],
            ]),
        );

        $resolver = new Resolver(
            new RuleLoader($brokenDir),
            new RuleEngine,
        );

        $this->expectException(RuleValidationException::class);

        try {
            $resolver->resolve([
                'credit_debit_indicator' => 'DBIT',
                'bank_transaction_code' => ['code' => 'ATM'],
            ], 'revolut');
        } finally {
            @unlink($brokenDir.'/revolut.json');
            @rmdir($brokenDir);
        }
    }

    public function test_atm_label_falls_back_to_default_when_blank(): void
    {
        // Codex review round 2 P1: a `cp .env.example .env` flow leaves
        // SPENDULA_ATM_CASH_LABEL= as an empty string. The constructor
        // must treat that as "use the default" rather than honouring '',
        // which would otherwise make every ATM withdrawal resolve to a
        // blank counterparty_name. Whitespace-only is treated the same way.
        $availableDir = __DIR__.'/../../../../config/counterparty-rules-available';

        foreach (['', '   ', "\t\n"] as $blankLabel) {
            $resolver = new Resolver(
                new RuleLoader($availableDir),
                new RuleEngine,
                $blankLabel,
            );

            $resolved = $resolver->resolve([
                'credit_debit_indicator' => 'DBIT',
                'bank_transaction_code' => ['code' => 'ATM'],
            ], 'revolut');

            $this->assertSame('ATM Cash', $resolved->name, 'Blank label must fall back to the documented default.');
            $this->assertSame(1, $resolved->level);
        }
    }

    public function test_atm_label_strips_surrounding_whitespace(): void
    {
        // Copilot review PR #44: a label like `" Cash withdrawal "` would
        // previously have leading/trailing whitespace stored verbatim into
        // counterparty_name, polluting dedup_hash inputs and payee-rule
        // keys. Constructor must trim before storing.
        $availableDir = __DIR__.'/../../../../config/counterparty-rules-available';
        $resolver = new Resolver(
            new RuleLoader($availableDir),
            new RuleEngine,
            '  Cash withdrawal  ',
        );

        $resolved = $resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'bank_transaction_code' => ['code' => 'ATM'],
        ], 'revolut');

        $this->assertSame('Cash withdrawal', $resolved->name);
        $this->assertSame(1, $resolved->level);
    }

    public function test_atm_label_is_truncated_to_64_chars(): void
    {
        $availableDir = __DIR__.'/../../../../config/counterparty-rules-available';
        $longLabel = str_repeat('Z', 100);
        $resolver = new Resolver(
            new RuleLoader($availableDir),
            new RuleEngine,
            $longLabel,
        );

        $resolved = $resolver->resolve([
            'credit_debit_indicator' => 'DBIT',
            'bank_transaction_code' => ['code' => 'ATM'],
        ], 'revolut');

        $this->assertSame(64, mb_strlen($resolved->name));
        $this->assertSame(1, $resolved->level);
    }
}
