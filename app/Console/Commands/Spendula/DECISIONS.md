# Spendula commands — local decision log

Decisions specific to artisan commands under `App\Console\Commands\Spendula\`.
Repo-wide decisions live in `SUMMARY.md` and `app/Services/Sync/DECISIONS.md`.

## 2026-04-30 — `tracking:snapshot` v1 ships the EB-balances path only; transactions-sum fallback deferred

`TrackingSnapshotCommand` reads the live balance for the account from
Enable Banking's `/accounts/{uid}/balances` endpoint and converts to
EUR via the configured `RateProvider`. SPEC §5.3 mentions a fallback
("sum stored transactions plus last known opening balance") if the EB
balances call fails; **v1 does not implement it.**

**Alternatives considered.** Sum `transactions.amount_milliunits` for
the tracking account from a known anchor. Rejected: there is no
`opening_balance` field in the schema today (verified via grep across
`database/migrations/` and `app/Models/`). Computing a balance from
zero would produce a meaningless absolute number on the first run, but
a self-consistent delta on every run thereafter. That ambiguity is too
sharp to ship without operator buy-in or a schema-level opening-balance
mechanism — both expand the scope of this issue.

**Consequences.** A per-account EB failure isolates to that account
(per-account warning, exit code 0 if any other account succeeded, exit
1 if all failed). When every account's EB call fails, the command exits
1 with no snapshots taken. Filed as follow-up:
`#TBD: tracking-snapshot fallback when EB balances unreachable` —
needs an `opening_balance` schema decision attached.

## 2026-04-30 — `tracking:snapshot` idempotency is operator-driven cadence, not in-command dedup

A second run on the same day produces a second `tracking_snapshots`
row whose `base_balance_milliunits` matches the first (within ±1
milliunit) and whose pushed YNAB delta is ≈ 0. The command does **not**
check `as_of_date` for prior same-day rows.

**Alternatives considered.** Refuse to run twice on the same UTC day,
or upsert into the existing row. Rejected: SPEC §5.4 explicitly leaves
cadence to the operator (manual in v1; no scheduler). An in-command
guard would (a) require a schema-level uniqueness invariant
(`bank_account_id, as_of_date`) the migration doesn't have today, and
(b) make repeated runs return a confusing "already snapshotted"
warning that operators would rightly ignore. Letting the second run
push a ~0 delta is inert — YNAB shows it as a zero-amount transaction
and the audit trail captures the cadence.

**Consequences.** Operators can re-run safely as a noop. `pushed_at`
on each snapshot row marks the actual run time. If a future feature
needs unique-per-day, add the invariant at the schema layer with an
explicit upsert behavior; the command's contract is "every run pushes
one transaction per account".

## 2026-04-30 — `interim_available` is the picked balance type, with `expected` then first-entry fallbacks

Enable Banking's `/balances` endpoint returns a `balances[]` array; the
exact mix of `balance_type` values varies by ASPSP. The snapshot
command picks `interim_available` first (the live current balance, the
most-comparable to YNAB's "what's actually there"), then `expected`,
then the first entry as a last resort.

**Alternatives considered.** Hard-fail when `interim_available` is
absent. Rejected: forces an early hard fail on a class of ASPSPs that
ship perfectly-usable balance data under another type (e.g. `expected`
for some banks). A logged-and-fall-back behavior keeps the snapshot
flowing while making the picked type observable in the structured
logs.

**Consequences.** Operators verifying a delta against a banking app
should check the structured log to see which `balance_type` the
snapshot anchored on. Filed as a possible follow-up:
`tracking_snapshots.eb_balance_type` column if the audit trail needs
to be visible in the DB rather than only in the log.

## 2026-04-30 — Balance Adjustment payload uses `cleared='reconciled'` + `approved=true`; no `PayloadBuilder` reuse

The pushed transaction in `TrackingSnapshotCommand::snapshotAccount`
is built inline with `cleared='reconciled'` and `approved=true`,
matching SPEC §5.3. This differs from `PushRunner`'s
`cleared='cleared'` / `approved=false` semantics — review-flow
transactions are *operator-approved* but not *reconciled* until the
operator confirms inside YNAB; balance-adjustment transactions are
both, since they only exist to mark a reconciled FX delta against the
tracking account.

**Alternatives considered.** Extend `PayloadBuilder` to take a "mode"
parameter or a payload-shape callable. Rejected: `PayloadBuilder`'s
contract is "build a payload from a Spendula `Transaction` row", and
balance-adjustments do not flow through `transactions`. Adding a mode
would make the builder polymorphic on a non-`Transaction` input,
which is the wrong abstraction. Per CLAUDE.md, abstract on the second
concrete need; today's two YNAB-write paths share neither input shape
nor field semantics.

**Consequences.** If a third YNAB-write path emerges (e.g. transfer
adjustments, automated category re-routes), revisit whether a shared
"YNAB transaction payload" type is warranted. For now, the inline
construction is the simpler shape.

## 2026-04-30 — `--account` takes the Spendula `bank_accounts.id` UUID, not the YNAB `account_id`

Both Spendula's `bank_accounts.id` and YNAB's `account_id` are UUIDs.
The flag accepts the **Spendula UUID**.

**Alternatives considered.** Accept either UUID (look up by both),
or use the YNAB UUID for consistency with how operators see accounts
in YNAB's UI. Rejected: the command operates on Spendula state, every
other Spendula command flag scopes by Spendula identifiers, and
accepting both UUIDs would make `--account=<unknown>` ambiguous in
its error message ("not found" — in which table?).

**Consequences.** Operators need to look up the Spendula UUID via
`spendula:status` (phase 4a) or `psql -c "SELECT id, ynab_account_id,
display_name FROM bank_accounts"`. Documented in the command's
`--help` output and SPEC follow-ups will reinforce this.

## 2026-05-01 — `spendula:status` dashboard layout + exit-code semantics (issue #16)

Phase 4a replaces the `spendula:status` stub with a single-screen,
read-only dashboard composed of four sections (per-bank consent,
queued transactions, last activity wall-times, push-stuck warnings)
plus a terse "Nothing to show" empty state. The command stays close to
existing repo conventions: a thin `Command` subclass that delegates
data-gathering to `App\Services\Status\StatusSnapshotBuilder` and
rendering to `App\Services\Status\StatusRenderer`.

1. **Snapshot/renderer split.** The builder emits a single
   `StatusSnapshot` value object and the renderer is pure (snapshot
   in → output out, no DB calls). The same snapshot drives the
   rendered output AND the exit code (`hasRedOrStuckRows()`), so
   tests don't have to parse terminal output to assert exit
   semantics. This makes the renderer cron-friendly (deterministic
   exit code from data shape) and the builder unit-testable in
   isolation.
2. **Four sections in fixed order.** Consent → queued counts → last
   activity → push-stuck warnings. The warnings section is omitted
   entirely (not "0 warnings") when there's nothing to flag — at-a-
   glance noise reduction on a clean run.
3. **`pushed`/`skipped` deliberately excluded from the queued
   counts.** The dashboard surfaces only `fetched`, `approved`,
   `transfer`, `tracking` to keep the at-a-glance snapshot from
   becoming a wall of historical numbers. Operators wanting
   per-status totals run an ad-hoc psql query.
4. **24h sync-staleness applies to active consent on active banks
   only.** A bank with `effectiveConsentStatus = 'expired'` (or
   `bank.active = false`) does NOT additionally trigger a stale-sync
   warning — the consent state already covers it. This collapses the
   double-warning surface and keeps the warnings section focused on
   single, actionable signals.
5. **Thresholds centralised in `App\Services\Status\Thresholds`.**
   `CONSENT_YELLOW_DAYS = 14`, `CONSENT_RED_DAYS = 3`,
   `SYNC_STALE_HOURS = 24`, `PUSH_STUCK_ATTEMPTS = 5`. Single source
   referenced by the builder and the unit tests, mirroring SPEC §9.4.
6. **`--include-mock` filters at the SQL builder, not the renderer.**
   One extra `WHERE banks.slug != 'mock'` clause in three places
   means the renderer can never accidentally surface a mock row.
   Filtering at the renderer would mean the snapshot tests special-
   case mock visibility — more surface for a one-line saving.
7. **Sync-freshness source is `bank_connections.last_synced_at`, not
   `bank_account_sync_state.last_successful_sync_at`.** SyncRunner
   stamps the connection-level field only when every attempted
   account on that connection succeeded. `bank_account_sync_state`
   is per-account and survives reauths, so MAX-ing it can show a
   bank as "fresh" even when the active consent has never synced
   (just-reauthed) or when one account silently failed. Account-
   level drill-down is a future surface.
8. **Stuck query is three-way.** `push_attempt_count >=
   PUSH_STUCK_ATTEMPTS AND status IN ('approved', 'transfer') AND
   ynab_transaction_id IS NULL`. PushRunner increments
   `push_attempt_count` on the success path too, so a row that
   retried 5 times then succeeded ends up at `push_attempt_count =
   5` AND `status = pushed`. The status/ynab-id filters keep those
   post-success rows from lingering forever and pinning exit code 1.
9. **Effective consent reconciles stored enum with `valid_until`.** A
   `bank_connections.status = 'active'` row whose `valid_until <
   now()` exists in the wild between expiry and the next sync run
   that lazily flips it to `expired`. The snapshot builder treats
   such rows as effectively-expired: red consent, blank
   `days_remaining`, and the stale-sync warning is suppressed.
   Dissolves the lazy-expiry double-warning window.
10. **All four reads run inside a single `REPEATABLE READ READ ONLY`
    transaction.** Postgres's default `READ COMMITTED` gives each
    `SELECT` its own snapshot, so under concurrent sync/push/auth-
    callback writes the dashboard could combine pre-push queued
    counts with post-push wall-times. The builder opens a
    `DB::transaction()` block that issues `SET TRANSACTION` to
    `REPEATABLE READ READ ONLY` and captures `now()` once into a
    `Carbon` instance reused for every threshold computation. Read-
    only and short-lived (4 small aggregates), so the lock impact on
    concurrent writers is nil.
