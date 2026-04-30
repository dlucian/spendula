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

];
