# Latest task summary

## GH issue #9 — Phase 3b: tracking-sync path (status tracking, bypass review/push)

### What changed

- `app/Services/Sync/MatchUpdateOrInsert.php` — `insert()` now branches
  status assignment in three steps: cutoff first (pre-cutoff →
  `skipped`), then `ynab_account_type === Tracking` (→ `tracking`,
  terminal), default (→ `fetched`). Class-level docblock updated to
  describe the new branch and reference SPEC §5.3 / §6.5.
- `app/Services/Sync/SyncRunner.php` — `syncConnection()` now skips
  only `ynab_account_type IS NULL` accounts. Previously skipped
  `Tracking`; this gate change is the prerequisite that lets tracking
  rows ever reach `MatchUpdateOrInsert`. Inline comment rewritten;
  unused `YnabAccountType` import removed.
- `app/Services/Sync/DECISIONS.md` — **new file**. Records (1) cutoff
  precedes the tracking branch, (2) drift on tracking rows uses the
  post-fetched log+Deduped path because tracking is terminal, and
  (3) review/push exclusion of tracking rows is structural via
  existing `status` filters, not a redundant `!= tracking` clause.
- `tests/Feature/Services/Sync/MatchUpdateOrInsertTest.php` — three
  new tests:
  - `test_tracking_account_post_cutoff_lands_as_status_tracking`
  - `test_tracking_account_pre_cutoff_still_lands_as_skipped`
    (cutoff wins)
  - `test_resync_of_tracking_row_preserves_status_tracking`
    (immutable status across re-sync)
- `tests/Feature/Services/Sync/SyncRunnerTest.php` — new
  `test_tracking_mapped_account_lands_transactions_with_status_tracking`
  exercises the end-to-end `spendula:sync` path against a tracking
  account fixture and confirms the gate change.
- `tests/Feature/Services/Review/ReviewSessionTest.php` — **new
  file**. One test: queue excludes `status = tracking` rows even
  when seeded alongside a fetched row on the same on-budget account.
  Uses the non-TTY branch's `{N} transaction(s) awaiting review`
  warning to assert the queue size structurally.
- `tests/Feature/Services/Push/PushRunnerTest.php` — new
  `test_tracking_status_rows_are_excluded_from_push` complements the
  existing `test_tracking_accounts_are_skipped` (account-level
  filter) by exercising the status-level filter.

### Assumptions made

- **Cutoff-wins ordering** mirrors SPEC §6.5: pre-cutoff history is
  uniformly `skipped` regardless of account purpose. A tracking
  account's `import_cutoff_date` is therefore the same kind of
  historical-noise boundary it is for on_budget accounts; the
  snapshot path (phase 3c) consumes only post-cutoff `tracking` rows.
- **Tracking accounts now actually flow through `SyncRunner`.** The
  previous gate (`!== OnBudget`) was a phase-1 placeholder; the issue
  body assumed only `MatchUpdateOrInsert` had to change, but the
  runner gate had to relax in tandem. The unmapped-account case
  (`ynab_account_type IS NULL`) stays excluded for the same reason
  as before — no `import_cutoff_date`, plus the
  `bank_accounts_currency_mapping_check` constraint.
- **Drift on `status = tracking` rows uses the same log+Deduped path
  as approved/pushed/skipped/transfer.** Hard-failing on a tracking
  row would stall every sync, and the operator can't act on it
  (tracking rows never reach review). Recorded in
  `app/Services/Sync/DECISIONS.md`.
- **Review/push exclusion is structural.** No new filters added —
  `ReviewSession::run()`'s `status = fetched` filter and
  `PushRunner::runLocked()`'s `status IN (approved, transfer)` +
  `ynab_account_type = on_budget` join already exclude tracking
  rows. The two new regression tests guard that structural exclusion
  against accidental relaxation.
- **No foreign-currency conversion at sync time.** Per SPEC §5.3 +
  §5.6, conversion happens at snapshot time (phase 3c); the sync row
  is stored at native currency.
- **Counterparty resolver still runs** on tracking rows so the
  operator can query historical foreign-currency transactions per
  SPEC §5.3 step 2 — no resolver changes.
- **No new YnabAccountType branches.** The enum has exactly two
  cases; the new conditional in `MatchUpdateOrInsert::insert()`
  handles the current branches and uses a `default` fallback to
  `fetched`. A future third case would require reviewing that
  conditional — recorded in the decision log.
- Tests run against Postgres via `RefreshDatabase`. Postgres session
  timezone was UTC for the test run (default config). 145 / 145
  feature+unit tests pass; PHPStan level 8 clean; Pint clean.

### Blast radius

- **Sync now writes to previously-unsynced tracking-mapped accounts.**
  Operators with already-mapped tracking accounts will see new rows
  appear in the `transactions` table on their next `spendula:sync`
  run, all with `status = tracking`. Those rows are invisible to
  review (`status = fetched` filter) and push
  (`status IN (approved, transfer)` filter, plus the bank-account
  `ynab_account_type = on_budget` join), so the existing operator
  workflow is unaffected.
- **No on-budget regression.** All existing on_budget sync, review,
  and push tests continue to pass; the new `match (true)` branch
  preserves the `Skipped`/`Fetched` outcomes for them.
- **`SyncRunner` gate relaxation accepts tracking accounts.** The
  unmapped-account case stays excluded; the test
  `test_pre_cutoff_transactions_are_skipped_and_never_enter_review_queue`
  confirms that pre-cutoff transactions are skipped and never enter
  the review queue. The new
  `test_tracking_accounts_still_skip_pre_cutoff_transactions_and_track_post_cutoff_transactions`
  test specifically locks in cutoff precedence on tracking accounts.

### Open threads

- **Phase 3c — `tracking:snapshot` consumer.** The actual reader of
  `status = tracking` rows lives in #3c. Today they accumulate
  silently; that's expected.
- **Foreign-currency conversion at snapshot time** is explicitly out
  of scope here per SPEC §5.3 / §5.6. Conversion uses
  `app/Services/ExchangeRates/RateProvider` (delivered in phase 3a).
- **Drift handling on tracking rows is best-effort.** If snapshot
  reconciliation later needs stricter handling (e.g. recompute on
  drift), it lives in the consumer, not in `MatchUpdateOrInsert`.
