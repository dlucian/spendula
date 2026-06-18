<?php

return [

    'base_currency' => env('SPENDULA_BASE_CURRENCY', 'EUR'),

    'callback_url' => env('SPENDULA_CALLBACK_URL', 'http://localhost:8000/banking/callback'),

    'enable_banking' => [
        'app_id' => env('SPENDULA_ENABLE_BANKING_APP_ID'),
        'private_key_path' => env('SPENDULA_ENABLE_BANKING_PRIVATE_KEY_PATH', 'storage/keys/enablebanking.key'),
        'env' => env('SPENDULA_ENABLE_BANKING_ENV', 'sandbox'),
        'base_url' => env('SPENDULA_ENABLE_BANKING_BASE_URL', 'https://api.enablebanking.com'),
    ],

    'ynab' => [
        'access_token' => env('SPENDULA_YNAB_ACCESS_TOKEN'),
        'plan_id' => env('SPENDULA_YNAB_PLAN_ID'),
        'base_url' => env('SPENDULA_YNAB_BASE_URL', 'https://api.ynab.com/v1'),
        'test_account_id' => env('SPENDULA_YNAB_TEST_ACCOUNT_ID'),
    ],

    'exchange_rates' => [
        'provider' => env('SPENDULA_EXCHANGE_RATE_PROVIDER', 'frankfurter'),
        'base_url' => env('SPENDULA_EXCHANGE_RATE_BASE_URL', 'https://api.frankfurter.dev/v1'),
    ],

    // GH #42 — synthetic counterparty label for DBIT cash withdrawals
    // identified by ISO 20022 `bank_transaction_code.code = "ATM"`.
    // The Resolver short-circuits to this label at level 1 before the
    // L0/L1 name-based branches; for the operator this collapses every
    // ATM withdrawal under one stable YNAB payee instead of fragmenting
    // them under the cardholder's own name (the SEPA-correct debtor).
    'resolver' => [
        // `?:` over `env(..., 'ATM Cash')` so a blank `SPENDULA_ATM_CASH_LABEL=`
        // line in `.env` (which Laravel parses as an empty string, NOT a
        // missing key) still falls back to the default — otherwise every
        // ATM withdrawal would resolve to an empty counterparty after a
        // plain `cp .env.example .env` (codex review round 2).
        'atm_cash_label' => env('SPENDULA_ATM_CASH_LABEL') ?: 'ATM Cash',
    ],

    // GH #14 — own-account transfer / FX classifier labels.
    // When OwnAccountClassifier detects that the destination IBAN belongs to
    // one of the operator's own bank_accounts:
    //   same-currency → counterparty_name = "<transfer_prefix> : <dest name>"
    //   different-currency → counterparty_name = fx_payee
    // Same-currency rows also get status=transfer so PayloadBuilder prepends
    // [TRANSFER] to the YNAB memo and the operator converts them to a native
    // transfer pair in YNAB (SPEC §8 v1 model). Cross-currency rows stay
    // fetched — the FX conversion is a genuine budget event.
    'own_account' => [
        'transfer_prefix' => env('SPENDULA_OWN_ACCOUNT_TRANSFER_PREFIX') ?: 'Transfer',
        'fx_payee' => env('SPENDULA_OWN_ACCOUNT_FX_PAYEE') ?: 'Currency Exchange',
    ],

    // GH #39 — auto-decision rule guards. Names that resolve to one of
    // these are NEVER converted into a payee_rules entry on first
    // decision; the operator can still decide each transaction manually,
    // but no rule is recorded that would silently auto-apply on future
    // syncs. Two reasons for the bake-in:
    //   - bank_internal_payees: PSD2 reporters sometimes use the bank's
    //     own brand as the counterparty when no real party is encoded
    //     (in-app top-ups, fee instruments). Same name → multiple
    //     unrelated transaction kinds.
    //   - operator_names: the operator's own legal name surfaces both
    //     as ATM withdrawals (skip-or-cash-spend) and as self-transfers
    //     (transfer). Same name → opposite verdicts.
    // Comparison is exact, case-insensitive. No wildcard / prefix
    // matching — list each variant explicitly. If pattern matching
    // becomes a real need, extend isOnDenylist() in PayeeRuleRecorder
    // and revisit this comment.
    'payee_rule_guards' => [
        'bank_internal_payees' => [
            'REVOLUT',
            'ING BANK', 'ING-V', 'ING ROMANIA',
            'ATM', 'ATM WITHDRAWAL',
            'BCP', 'MILLENNIUM BCP',
        ],
        'operator_names' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SPENDULA_OPERATOR_NAMES', '')),
        ))),
    ],

];
