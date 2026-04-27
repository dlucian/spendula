<?php

/*
 * Baseline bank fixtures (SPEC §4.1). Ships only the `mock` bank — used by
 * tests and the local sandbox flow.
 *
 * Operator banks (the ones the operator actually uses) are NOT defined here.
 * This file ships in a public repo and listing real banks would leak which
 * institutions a given operator banks with. Operators add their own banks
 * directly into the `banks` table via `spendula:banks:add`, which keeps that
 * choice out of source control entirely.
 *
 * `spendula:banks:sync` upserts the fixtures below; it never deactivates or
 * deletes operator-added rows.
 */

return [

    'mock' => [
        'display_name' => 'Mock ASPSP',
        'aspsp_name' => 'Mock ASPSP',
        'aspsp_country' => 'FI',
        'psu_type' => 'personal',
        'default_currency' => 'EUR',
        'sync_lookback_days' => 90,
    ],

];
