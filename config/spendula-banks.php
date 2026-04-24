<?php

/*
 * Bank catalogue (SPEC §4.1). Phase 1 ships only `mock`; real banks arrive in
 * phase 2 once the production Enable Banking app is approved and IBANs are
 * whitelisted (SPEC §9.5, PLAN §2c).
 *
 * Keys are the `banks.slug` primary key. Rows removed from this file are NOT
 * deleted by `spendula:banks:sync` — they are marked `active = false`.
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
