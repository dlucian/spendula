# Sync — local decision log

Decisions specific to the sync subsystem. Repo-wide decisions live in `SUMMARY.md`.

## 2026-05-19 — `sync_run_errors.error_detail` carries the EB response body (GH #2)

`SyncRunner::logError` used to persist only `substr($e->getMessage(), 0, 1000)`,
even though `EnableBankingException::$body` already carried the parsed
JSON envelope from upstream. Diagnosing prod failures meant SSH-and-query
to read `raw_payload`, then guess.

Now both `SyncRunner` and `PushRunner` route through
`App\Services\Errors\ErrorDetailFormatter`. The persisted string is the
existing exception message (grep-compatible prefix), then a blank line,
then `Response: <json-encoded body>`. The 1000-char cap still applies
but truncation lands AFTER appending — the prefix is never lost.

**Alternatives considered.** A new structured `error_body` jsonb column.
Rejected: requires a migration, doubles the storage surface, and the
freeform `error_detail` field is already sized for the use case. If a
future panel needs structured field access (e.g. group-by error code),
we can land the column then.

**Consequences.** `error_detail` now exceeds the bare prefix length on
HTTP errors with a non-null EB body. Anything that grep-matched the
prefix still works. `spendula:status` and a new inline error tail on
`spendula:sync` / `spendula:push` consume the same field and collapse
the embedded `\n\nResponse: ` marker into a single-line ` — Response: `
for display.

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

## 2026-05-11 — Sync non-BOOK filter reads EB's `status`; DB column keeps legacy name `transaction_status` (GH #46)

`SyncRunner::syncAccount()` and `MatchUpdateOrInsert::parseIncoming()`
both read EB's `status` field directly from the raw payload. The DB
column that stores the persisted value remains
`transactions.transaction_status` — a legacy name from the original
Phase 1a schema (commit `20ca3a1`), kept rather than migrated.

**Why it stayed dormant until now.** The original SyncRunner code
read `$ebTransaction['transaction_status']`, matching the DB column
name but not EB's actual payload schema (which uses `status`). The
filter never fired in production: BCP and Revolut LT, the two
banks connected before May 2026, do not surface PDNG / INFO rows
in their AIS feeds — every row they emit is BOOK, and the parser's
default fallback (`'BOOK'` when the key is absent) made the row land
correctly anyway. The bug only became observable when ING Romania
came online and started returning pending card holds (`status =
PDNG`, `booking_date = null`) at the top of every transactions
response, which fell through to the parser, threw on the missing
`booking_date`, and aborted the ING Romania business EUR account's
sync entirely (0 transactions ingested over 3 sync runs).

**Alternatives considered.**

1. Rename the DB column `transaction_status → status`. Rejected: 
   requires a migration plus updates to the Transaction model,
   ~10 test files that insert into the column by name, and SPEC §4.
   The naming mismatch is a one-time confusion that inline docblock
   notes plus the SPEC §6.2 blockquote should prevent from recurring.

2. Persist the raw payload first, then attempt parse out-of-band so
   that no row is silently dropped. Rejected for this PR: the
   existing comment at `SyncRunner::syncAccount()` lines 354-361
   explains why advancing past an unparseable row is dangerous — EB
   does not allow continuation-key replay, so the 7-day overlap
   window is the only recovery path. Persist-first-then-parse would
   need a parallel re-parse pipeline and a way to drain a backlog of
   `unparseable` rows after a parser fix. Worth doing as a separate
   workstream; not needed to unblock Rulaj.

3. Special-case `booking_date = null` by falling back to
   `value_date` or `transaction_date`. Rejected: would invent a
   booking date that the bank hasn't yet committed to, breaking
   `dedup_hash` stability — the same row could insert twice with
   different fabricated dates if the bank later books it on yet
   another date.

**Consequences.** Future PDNG / INFO / OTHR / FUTR rows from any bank
filter out cleanly pre-parse. The DB column name is now permanently
misaligned with EB's schema; the SPEC and inline docblocks carry
explicit notes to keep the next reader oriented. If a future feature
ever needs the genuine EB value for a stored row, the truth is in
`raw_payload->>'status'` (which is what the column should have
shadowed all along).

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

## 2026-06-19 — GH #16 codex follow-up: three post-review fixes

**Decision.** Three correctness issues identified by codex review of the #16 branch:

1. *Funding-pushed guard in applyLink.* `applyLink` originally promoted the funding
   leg unconditionally before checking whether it was already pushed. The funding-pushed
   case is now guarded at the top of `applyLink`, symmetrically with the existing
   destination-pushed guard. The destination phantom is still suppressed in both cases
   so YNAB never sees a standalone credit. The link is asymmetric in the funding-pushed
   case (destination→funding is set; funding→destination is not) — this is intentional:
   the funding row is already in YNAB's ledger and must not be edited.

2. *Re-sync dedup in the count() > 1 branch.* The `MatchUpdateOrInsert` `count > 1`
   path previously inserted a new occurrence unconditionally, so a routine overlap
   re-sync of an existing row (when two+ same-normalized rows were present) would
   create a spurious occurrence-3. The fix mirrors the existing count===1 raw-comparison
   logic: if the incoming raw counterparty matches any existing row exactly, that row is
   updated (deduplicated) instead of a new occurrence being inserted. Only a genuinely
   new raw — matching none of the existing rows — triggers the occurrence-increment.

3. *Ambiguity-safe counterpart selection.* The candidate-matching loops in
   `findDestinationCounterpart` and `findFundingCounterpart` previously returned the
   first match in an arbitrary iteration order. With two same-amount top-ups on the
   same card within the window, this could mis-pair nondeterministically. The fix:
   `ORDER BY booking_date, id` for stable iteration; collect all matches; pick the
   unique closest by booking_date; if two+ tie, log `cross_source.ambiguous_match`
   and return null. Never guess on a financial pairing.

**Alternatives rejected.** For fix 3, picking the first in ORDER BY id would be
deterministic but still wrong (arbitrary ID order ≠ correct pairing). Logging and
returning null forces the operator to investigate the ambiguous pair manually, which
is the only correct outcome when the data is genuinely ambiguous.

**Consequences.**
- A pushed funding leg that arrives after the destination has been synced (previously
  corrupt: status regressed to transfer) now stays pushed and logs late_pair.
- Re-syncing a known top-up no longer creates phantom occurrence-3 rows.
- Two equidistant same-amount top-ups on the same window leave both unlinked with a
  logged warning; manual YNAB reconciliation is required.
