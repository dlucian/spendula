# Latest task summary

## GH issue #3 — identification_hash matcher collapsing multi-account consents

### What changed

- `app/Services/EnableBanking/CallbackHandler.php`
  - Lookup is driven solely by the SPEC §10.1 primary
    `identification_hash`. The earlier "match on any of the eight
    hashes EB returned" approach was order-dependent under re-auth —
    a sibling whose secondary hashes overlap with another sibling's
    (Revolut LT returns the LT IBAN as `account.account_id.iban` for
    both EUR and RON accounts) could bind into the wrong row depending
    on which Eloquent row `first()` returned. Secondaries are still
    persisted via `syncIdentifiers` for fidelity, but they no longer
    drive matching.
  - `whereNotIn('bank_account_id', $touchedAccountIds)` excludes any
    row already upserted in this same callback — the per-callback
    defense in depth that prevents two EB accounts in one consent
    from collapsing into one row.
  - **Secondary-hash fallback for re-consent.** If the primary lookup
    misses, fall back to a `whereIn(allHashes)` lookup that excludes
    touched rows. Reuse an existing row only when the secondary
    matches point unambiguously at a single bank_account; ambiguity
    falls back to insert. Handles the case where EB rotates the
    primary across re-consents while keeping the old hash as a
    secondary.
  - `handle` threads a `Set<bank_account_id>` through the per-account
    loop so the lookup has the touched set.
  - `syncIdentifiers` keeps primary collisions fatal (they would break
    the SPEC §10.1 "exactly one primary per account" invariant), and
    tolerantly skips ALL secondary collisions. Since matching no
    longer depends on secondaries, a secondary stuck on the first
    claimer cannot misroute a future re-auth, and tolerating them lets
    re-auths in arbitrary EB-account-order succeed.

- `tests/Feature/Http/BankingCallbackControllerTest.php` — three new
  regression tests:
  * `test_multi_account_same_holder_does_not_collapse_into_one_row`
    drives the original Revolut fixture through the callback and
    asserts two distinct rows, currencies, IBANs, sessions, and
    primary identifiers.
  * `test_re_auth_in_reverse_account_order_keeps_two_rows` runs the
    callback twice with reversed account order on the second call and
    verifies row reuse (no inserts), exercising the secondary-skip
    tolerance.
  * `test_re_consent_with_rotated_primary_reuses_existing_row`
    rotates EB's primary identification_hash across two consents
    while keeping the old primary in identification_hashes; verifies
    the secondary-fallback reuses the existing row instead of
    creating a duplicate.

### Assumptions made

- Local dev stays on sandbox EB; the fix is TDD'd against the captured
  production fixture. No live EB call was made.
- Postgres session timezone was UTC for the test run (default config).
- `bank_account_identifiers.hash` keeps its global `unique` constraint
  — the secondary tolerant-skip is what reconciles the constraint with
  EB's habit of emitting hashes that genuinely repeat across an
  operator's accounts.

### Blast radius

- `BankingCallbackControllerTest::test_first_time_auth_persists_*` and
  `test_second_authorization_supersedes_*` keep passing — both use
  opaque non-recipe hashes and the matching path is unchanged from
  their perspective.
- The supersede-and-relink path (re-auth of a single account) is
  untouched at the matching level: the previous callback's account is
  not in `touchedAccountIds` of the new callback, lookup matches it
  by primary hash, normal update flow runs.

### Limitations / accepted trade-offs

- **EB promotes a shared secondary to a primary on the OTHER sibling.**
  The lookup requires `is_primary = true`, so the rotated hash on the
  former owner's row (now `is_primary = false`) does not match. The
  callback then falls through to insert and the syncIdentifiers
  primary-collision check throws — surfaced as 502 by the controller.
  Operator must clean up the cross-row identifier manually before
  re-auth.

- **Chained primary rotations dropping intermediate hashes.** If EB
  rotates a primary, then later rotates again without including the
  previous primary in `identification_hashes`, the secondary-fallback
  cannot reconnect because the only DB-overlap is via a now-secondary
  former primary. The callback throws as a primary-collision 502.
  Distinguishing this from a brand-new sibling sharing only a
  non-discriminating secondary needs identifier-history tracking the
  schema does not have today — out of scope.

### Out of scope

- **Recovery from previously-collapsed consents.** A deployment that
  hit the bug before this fix has multiple siblings' primaries parked
  on a single `bank_account` row. After this fix, re-auth for that
  bank will throw a primary-collision RuntimeException when the second
  sibling tries to claim its primary. Recovery requires a separate
  repair tool because it needs (a) a discriminator beyond currency to
  decide which sibling keeps the row when both have the same currency
  (e.g. two EUR accounts at the same operator), and (b) a data
  migration to move historical transactions and `sync_state` from the
  collapsed row onto the split-out sibling so the next sync doesn't
  reimport the entire lookback window. Out of scope for this PR; the
  one known affected deployment (this user's prod) was DB-wiped on
  2026-04-27 to clear the corrupt state, so the immediate path is
  prevention-only.

### Open threads

- After merge: re-link BCP and Revolut on prod, confirm Revolut yields
  two `bank_accounts` rows (EUR LT + RON RO).
- SPEC §4.4 still describes the old "match on any of the collected
  hashes" rule. Update either in this PR or a follow-up before public
  release.
- Counterparty resolver tuning for BCP. PLAN §2d, separate task.
- Doc scrub before public release: SPEC.md / PLAN.md still reference
  specific operator banks (Millennium, Revolut, ING RO, UniCredit) in
  examples.
- ING RO with 5 personal accounts will exercise this fix more broadly
  once a consent is taken.
- A `spendula:bank-accounts:repair` command for collapsed-state
  deployments — covers the recovery scenario taken out of scope above.
