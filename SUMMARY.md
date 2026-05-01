# Latest task summary

## GH issue #20 — Review CLI: `u` to undo the last decision

### What changed

- `app/Services/Review/TransactionActions.php` — new
  `revertToFetched(Transaction): Transaction`. Sets
  `status = TransactionStatus::Fetched`, clears `skipped_at` and
  `skip_reason`, persists. Idempotent on a row already at `fetched`.
  Documented as the inverse of `approve`/`skip`/`markTransfer`,
  intended for the review-CLI undo flow; rows mass-approved via
  `bulkApproveTrivial` are explicitly out of scope (they never had an
  interactive decision and so are not on the in-memory undo stack).
- `app/Services/Review/ReviewSession.php` — three structural changes:
  1. **Optional 4th constructor arg `?Closure $keyReader`.** Production
     callers omit it and the loop continues to read `fgetc(STDIN)` via
     a new private `readKey()`. When injected, `stdinIsTty()` returns
     true unconditionally so tests can drive the keypress loop without
     a real TTY (orthogonal to the existing
     `app()->runningUnitTests()` guard, which still triggers the
     non-TTY warn-and-exit branch when no reader is injected — keeping
     the existing tracking-status exclusion test green).
  2. **`foreach` → deque pump.** The eagerly-loaded queue is now a
     mutable `list<Transaction>` consumed by `array_shift`; the
     `'u'` handler `array_unshift`-es the just-undone row and the
     currently-displayed (still-undecided) row back to the front and
     decrements `$position` by 2 so the outer loop re-shifts and
     re-counts cleanly.
  3. **New `'u'` case** in the keypress switch. Pops the in-memory
     LIFO undo stack; on empty prints `Nothing to undo.` and
     re-prompts the same row without a DB write. On non-empty calls
     `TransactionActions::revertToFetched()` (against a `fresh()`
     reload of the row, defensive against another process having
     mutated it between approve and undo), decrements the matching
     stats counter (`approved` / `skipped` / `transferred`) plus
     `reviewed`, prints `↶ undid: {id} {label}→fetched`, and re-queues
     both rows.
  - The per-row prompt now reads
    `[a]pprove  [s]kip  [t]ransfer  [u]ndo  [d]etails  [q]uit > `.
  - Class-level docblock notes the undo stack is in-memory and
    discarded on `q` / process exit, and that `bulkApproveTrivial`
    rows are unreachable from undo.
- `tests/Feature/Services/Review/TransactionActionsTest.php` —
  4 new tests for `revertToFetched`: from approved, from skipped
  (clears reason + `skipped_at`), from transfer, idempotent on
  already-fetched.
- `tests/Feature/Services/Review/ReviewSessionTest.php` —
  7 new tests covering the keypress loop with an injected key reader
  and a fake `Command` (in-memory `BufferedOutput` + `OutputStyle`,
  `ArrayInput` with a stubbed stream feeding `Command::ask()` for the
  skip prompt). Coverage: approve+undo round-trip, skip+undo clears
  reason, transfer+undo, empty-stack `Nothing to undo.` (no DB
  write), three LIFO undos in sequence, quit after a partial undo
  preserves post-undo counters.
- `docs/SPEC.md` §7.1 — keypress list and ASCII prompt updated to
  include `[u]ndo`. The new bullet documents the LIFO + in-memory
  semantics and notes the `bulkApproveTrivial` carve-out.

### Assumptions made

- **Production EB / OAuth state.** No EB or YNAB calls in this slice;
  the review loop is a local DB-only transition. `OAuth state
  assumptions` are irrelevant for this change.
- **Mock ASPSP behaviours.** Same — no PSD2 round-trips touched.
- **Postgres session timezone is UTC** during the test run (default
  config). `RefreshDatabase` rolls back the per-test mutations; all
  183 / 183 tests pass; PHPStan level 8 clean; Pint clean.
- **In-memory undo stack only.** SPEC §7.1 says the stack is unbounded
  within a session and discarded on quit. No new schema, no new
  columns, no migration.
- **`fresh()` defensive reload.** Before reverting we reload the row
  from the DB. The cost is one round-trip per undo; the benefit is
  that any concurrent mutation (vanishingly unlikely on a single-
  operator CLI) doesn't get silently overwritten by a stale model.
  This matches the comprehension rule on idempotency for review-loop
  actions.
- **Key reader closure, not a stream.** The simplest injection
  surface for tests is a `Closure(): string` returning one byte at a
  time. Production stays at `fgetc(STDIN)`; an injected reader also
  bypasses the TTY-check fallback so the loop runs in unit tests
  without raw-mode `stty`. `AppServiceProvider` does not bind
  `ReviewSession`, so `ReviewCommand` continues to instantiate it
  directly without the optional arg — call site unchanged.

### Blast radius

- **Direct callers of `ReviewSession`:** only `ReviewCommand`. Its
  `new ReviewSession($this, $actions)` call still type-checks (the
  4th constructor argument is optional with a default of `null`).
- **Direct callers of `TransactionActions`:** `ReviewSession` (now
  also calling `revertToFetched`) and `ReviewCommand` (only for the
  `--bulk-approve-trivial` path). New method is additive; existing
  approve/skip/markTransfer/bulkApproveTrivial signatures unchanged.
- **DB schema:** no migration. No new columns, indexes, or
  constraints. Existing `transactions.status`, `skipped_at`,
  `skip_reason` are the only fields touched on revert.
- **The non-TTY tracking-exclusion test** in `ReviewSessionTest`
  still works because the `runningUnitTests()` short-circuit is only
  bypassed when a key reader is injected.
- **Push pipeline (`spendula:push`)** queries
  `status IN ('approved', 'transfer')`; reverting a row to `fetched`
  removes it from the push queue, which is exactly the intended
  semantics. No interaction surprises.

### Open threads

- `bulkApproveTrivial` rows are unreachable from undo. Documented in
  the new docblock; if operators ask for "undo my last bulk-approve
  batch" that's a separate ticket and probably wants a different
  primitive (e.g. revert-by-`updated_at >=` window).
- The `↶` glyph in the undo banner is a UTF-8 hairpin-arrow; the EB
  TTY conventions in this repo already use `─` and `→`, so this is
  consistent. If the production terminal mis-renders, the change is
  one string.

---

## GH issue #10 — Phase 3c: tracking:snapshot command

### What changed

- `app/Console/Commands/Spendula/TrackingSnapshotCommand.php` — Phase-1
  stub replaced with the full snapshot implementation. Walks every
  active tracking-mapped `bank_account` (or the single one passed via
  `--account=<spendula-uuid>`), fetches the live native-currency
  balance via `EnableBanking\Client::accountBalances`, picks the most
  comparable balance type (`interim_available` → `expected` → first),
  converts to EUR via `RateProvider::getRate(...)` using
  `bcmul(..., 0)` truncation at the milliunit boundary, fetches the
  current YNAB balance via `Ynab\Client::account`, computes the
  delta, and pushes a single `Balance Adjustment` transaction
  (`cleared='reconciled'`, `approved=true`) per account. Records a
  `tracking_snapshots` row per pushed delta. `--dry-run` skips both
  side effects but performs the same lookups so operators can preview
  deltas. Wraps the whole run in
  `AdvisoryLock::withLock(AdvisoryLock::TRACKING_SNAPSHOT, …)`.
- `app/Services/Ynab/Client.php` — new `account(string $ynabAccountId):
  array` method; mirrors the existing retry/classification scaffolding
  via `requestJson('GET', "/plans/{plan_id}/accounts/{id}")`.
- `app/Services/EnableBanking/Client.php` — new `accountBalances(string
  $uid): array` method; mirrors `accountTransactions` (idempotent GET,
  5xx retry ladder, typed exceptions).
- `app/Console/Commands/Spendula/DECISIONS.md` — **new file**. Five
  decisions: (1) v1 ships EB-balances path only, fallback deferred;
  (2) idempotency is operator-driven cadence; (3) `interim_available`
  → `expected` → first-entry as picked-balance fallback chain;
  (4) `cleared='reconciled'` + `approved=true` are SPEC-mandated and
  inline (no `PayloadBuilder` reuse); (5) `--account` takes the
  Spendula UUID, not YNAB's.
- `tests/Feature/Commands/Spendula/TrackingSnapshotCommandTest.php` —
  **new file**, 10 tests: happy path (payload + snapshot row),
  `--dry-run`, `--account` scope, invalid UUID, non-tracking-account
  rejection, same-day idempotency (delta-zero on second run), advisory
  lock contention (via second PDO connection — Postgres advisory locks
  are per-session), rate-provider unreachable abort, per-account EB
  isolation (one fails, one succeeds → exit 0), all-EB-fail abort.
- `tests/Feature/Services/Ynab/ClientAccountTest.php` — **new file**,
  4 tests: path correctness + envelope auto-unwrap, 401 → auth, 429 →
  rate-limit (after one retry), 5xx → server (after retries).
- `tests/Feature/Services/EnableBanking/ClientAccountBalancesTest.php`
  — **new file**, 5 tests: path + JWT bearer, 401, 403, 429, 5xx.
- `tests/Feature/Commands/Spendula/StubCommandsTest.php` — removed the
  `'spendula:tracking:snapshot'` entry; the stub no longer prints "not
  yet implemented".
- `docs/PLAN.md` — Phase 3c struck through `~~…~~ (done 2026-04-30)`,
  with a one-line note that the EB-balances-only fallback decision is
  recorded in `DECISIONS.md`.

### Assumptions made

- **Mock ASPSP behaviours.** The Mock ASPSP doesn't expose `/balances`
  realistically, so the integration-style tests stub the EB balances
  endpoint via `Http::fake`. Production EB returns a `balances[]`
  array with shape `{balance_type, balance_amount: {amount, currency},
  credit_debit_indicator}`; the test fixtures match that shape. The
  picked-balance preference order (`interim_available` → `expected` →
  first entry) is recorded in DECISIONS so any production-EB drift
  is observable through the structured log.
- **YNAB API responses are stubbed via `Http::fake`.** The
  `data.account.balance` field is an integer (EUR milliunits), since
  YNAB plans are single-currency per SPEC §5.3 and the YNAB API
  returns balances in the plan's currency. No live YNAB hit; pin
  against contract.
- **Rate-provider weekend rollback semantics persisted.** The memo's
  "as of {YYYY-MM-DD}" uses `$rate->rateDate` (the actually-published
  date), so a Sunday snapshot referencing a Friday rate has
  `memo = "...as of 2026-04-23"` while
  `tracking_snapshots.as_of_date = 2026-04-25` (the day the snapshot
  was taken). The `as_of_date` column captures snapshot-day, not
  rate-day. SPEC §5.3 was silent; pinned in DECISIONS.
- **Fallback path entirely deferred.** No `opening_balance` field
  exists in the schema today. Computing a balance by summing
  `transactions.amount_milliunits` from zero would produce a
  meaningless absolute number on the first run, but a self-consistent
  delta thereafter. Decision: ship EB-balances path only; file the
  fallback as `#TBD: tracking-snapshot fallback when EB balances
  unreachable` with the opening-balance schema decision attached.
- **Postgres session timezone was UTC** during the test run (default
  config). `pushed_at` and `as_of_date` round-trip correctly through
  `RefreshDatabase`. 164 / 164 tests pass; PHPStan level 8 clean;
  Pint clean.
- **Advisory lock test uses a second PDO connection.** Postgres
  advisory locks are per-session and re-entrant within the same
  session. The test process and the artisan command share the test's
  DB connection, so a same-session `tryAcquire` would succeed
  re-entrantly. The test opens a separate PDO and acquires from
  there to make the command's `tryAcquire` actually fail. Captures
  the LockBusyException-test-coverage gap previously flagged in
  observation #397.
- **Money math via `bcmul((string) $native_milliunits, $rate->rate, 0)`
  with `(int)` cast at the milliunit boundary.** `bcmul` with scale 0
  truncates toward zero (matching `Money::toMilliunits`'s direction).
  PHPStan required a runtime `is_numeric($rate->rate)` guard to
  satisfy `numeric-string` typing — rate provider already guarantees
  this; the guard surfaces a clear `RuntimeException` if a future
  provider regresses.
- **Per-account EB-failure isolation matches `PushRunner::pushGroup`.**
  Exit 0 if at least one account succeeded; exit 1 if all failed; exit
  1 unconditionally on rate-provider unreachable, YNAB auth, YNAB
  rate-limit, or lock contention. Documented in the command's class
  docblock.

### Blast radius

- **New side-effect surface.** Operators running
  `spendula:tracking:snapshot` will see one new YNAB transaction per
  active tracking account per run, plus one new `tracking_snapshots`
  row each. No changes to sync/review/push paths; the snapshot
  command is the only consumer of `status = tracking` rows alongside
  review/push (which filter them out structurally).
- **Two new client methods (`Ynab::account`,
  `EnableBanking::accountBalances`)** are public on shared
  singletons. Other commands could call them, but no other Phase 1–3
  code path does today. Adding new callers is fine; the methods
  reuse the existing retry ladders.
- **No schema changes.** `tracking_snapshots` table already existed
  from Phase 1; this issue is the first to write to it.
- **Existing tests untouched.** `StubCommandsTest` shrinks by one
  entry; everything else compiles and passes unchanged.

### Open threads

- **Transactions-sum fallback path** for when EB `/balances` is
  unreachable. Filed as `#TBD: tracking-snapshot fallback when EB
  balances unreachable`; needs an `opening_balance` schema decision
  attached.
- **`tracking_snapshots.eb_balance_type` column** to persist the
  picked balance type (currently logged only). Filed as a follow-up
  if operators want the audit trail visible in DB rather than only in
  the structured log.
- **Automatic snapshot cadence** is explicitly out of scope per SPEC
  §5.4 (manual in v1; no scheduler).
- **Per-snapshot rate-provider override** (not currently supported;
  always uses the configured provider). No demand today.
