# Sync — local decision log

Decisions specific to the sync subsystem. Repo-wide decisions live in `SUMMARY.md`.

## 2026-04-30 — Cutoff precedes the tracking-status branch (phase 3b)

`MatchUpdateOrInsert::insert()` checks `import_cutoff_date` first, then
the account's `ynab_account_type`. Pre-cutoff transactions on a
tracking-mapped account land as `status = skipped` (with
`skip_reason = "before import cutoff"`), not `tracking`.

**Alternatives considered.** Branch on `ynab_account_type` first, so a
tracking account always emits `tracking` rows. Rejected: SPEC §6.5 says
"transactions whose booking_date is before import_cutoff_date are
inserted with status=skipped and never reviewed". The cutoff is a
historical-noise boundary that applies regardless of account purpose;
honouring it on tracking accounts keeps the operator's import-cutoff
decision uniform across both kinds of accounts and stops a phase-3b
backfill from materialising a year of pre-cutoff snapshots.

**Consequences.** A tracking account with no `import_cutoff_date` set
still emits `tracking` rows for everything (cutoff is `null`-safe); a
tracking account whose cutoff was set before mapping continues to
absorb pre-cutoff rows as `skipped` instead of double-counting them as
both `tracking` *and* skipped.

## 2026-04-30 — Drift on tracking-status rows uses the post-fetched path (log + Deduped)

`MatchUpdateOrInsert::update()` already throws on amount/currency/cdi/
booking-date drift only when `status = fetched` (operator hasn't acted
yet) and otherwise logs + returns `Deduped`. `tracking` is terminal —
the operator can't act on it, and a hard fail would make every
overlapping sync exit non-zero with no recovery path. Tracking rows
therefore fall into the same log-and-Deduped branch as
approved/pushed/skipped/transfer.

**Consequences.** Bank-side drift on a tracking row is observable in
the structured log (`event=sync.drift_after_review`) and never
propagates to the snapshot path, which reads the row as it stood at
first insert. If we later need stricter handling for tracking rows
(e.g. recompute snapshot inputs on drift), it lives in the snapshot
consumer, not here.

## 2026-04-30 — Review/push exclusion of tracking rows is structural, not an explicit check

Neither `ReviewSession::run()` nor `PushRunner::runLocked()` adds an
explicit `status != tracking` filter. Review filters by
`status = fetched`; push filters by
`status IN (approved, transfer)` and joins to bank accounts with
`ynab_account_type = on_budget`. Both filters already exclude tracking
rows by construction; piling on a redundant `!= tracking` clause would
duplicate the invariant and risk drift if the status enum grows.

**Consequences.** Adding a future status (e.g. `tracking_pushed` if
snapshots ever round-trip into YNAB) requires re-evaluating the queue
filters in both consumers, but no special-casing needed today. The
`PushRunnerTest::test_tracking_status_rows_are_excluded_from_push`
and `ReviewSessionTest::test_queue_excludes_tracking_status_rows`
regressions guard the structural exclusion against accidental
relaxation of either filter.
