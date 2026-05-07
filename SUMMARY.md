# Latest task summary

## Counterparty rule engine — JSON-driven, per-bank cleanup rules

### What changed

- Bank-specific cleanup patterns moved out of `Resolver.php` and
  into per-bank JSON rule files at
  `config/counterparty-rules-available/<bank>.json`. Operators
  enable rules via symlinks in
  `config/counterparty-rules-enabled/<bank>.json` (Apache mods-style).
- New `RuleEngine` + `RuleLoader` + `Rule` / `RuleFixture` value
  objects + `PostHook` finalizers under
  `app/Services/Counterparty/Rules/`.
- Resolver shrunk from ~290 lines to ~115 — L0/L1/L3/L4 ladder
  stays in code (universal); L2 delegates to the rule engine
  with the transaction's bank slug.
- Two rule files shipped: `bcp.json` (13 rules covering BCP's
  COMPRA / TRF / DD / PAGSERV / PAG BXVAL / LEV ATM /
  COM.MAN.CONTA shapes plus operator-specific AIR SERBIA, Seguro
  Viagem, LE FOURNIL D patterns) and `ing-ro.json` (1 rule for
  ING RO Business structured remittance).
- Four CLI commands:
  `spendula:counterparty:rules:add` (interactive; supports
  `--from-transaction=<id>` to pull a real remittance, auto-derive
  fixture output, and preview impact on existing transactions
  before saving),
  `spendula:counterparty:rules:enable <bank>` (symlink),
  `spendula:counterparty:rules:disable <bank>` (unlink),
  `spendula:counterparty:rules:test [--bank=<slug>]` (standalone
  fixture runner, parallel of the auto-discovered phpunit test).
- Auto-discovered `RuleFixtureSelfTest` walks every rule's fixtures
  in every available rule file; runs as part of `vendor/bin/phpunit`.
- Supersedes (and closes) PR #26 — its trailing-reference and
  embedded-id patterns are reframed as data rules in `bcp.json`.

### Assumptions made

- **Rule files at `config/counterparty-rules-available/`**: this
  directory is committed; new operators get the shipped rule
  library on checkout. The `enabled/` directory contains gitignored
  symlinks per operator preference.
- **Bank slug is per-transaction**: derived from
  `Transaction::bankAccount::bank_slug`. The Resolver accepts an
  optional `?string $bankSlug` parameter; null means no rules
  apply (trimmed remittance returned as-is).
- **`dedup_hash` independence**: unchanged from prior work — the
  hash uses raw EB fields, not the resolver's L2 output. Rule
  changes don't shift dedup hashes for existing rows.
- **Tests required for every rule**: validated at add-time (the
  CLI refuses to save a rule whose fixture doesn't pass) and at
  load-time (`RuleLoader` throws on empty `tests` array).

### Blast radius

- **`spendula:sync` path**: `MatchUpdateOrInsert` now passes
  `$bankAccount->bank_slug` to the resolver. The resolver looks up
  rules for that bank and applies the engine. Behavior on shipped
  banks (bcp, ing-ro) is preserved.
- **`spendula:counterparty:recompute --bank=bcp`** (dry-run on
  the operator's 337-row dataset) reports
  `name_changed=0` — the rule engine reproduces the prior
  code-based resolver output exactly (including the operator's
  PR #26-era cleanups for AIR SERBIA, Seguro Viagem, and LE
  FOURNIL D).
- **No effect on `dedup_hash`**: same as prior PRs.
- **No effect on existing tests**: full suite 282/282 (incl. 23
  rule fixtures via the auto-discovered self-test, 6 add-command
  tests, 6 enable/disable tests, 3 test-command tests). PHPStan
  level 8 clean.

### Open threads

- **`_generic.json` for cross-bank cleanup**: deferred to v2.
  Per-bank rules cover all observed shapes today. If a truly
  cross-bank pattern appears, add a `_generic.json` (loaded for
  every transaction).
- **Rule shadowing detection**: deferred to v2. Operators rely on
  manual ordering (most-specific-first) plus the
  `--from-transaction` preview to spot ordering mistakes before
  saving.
- **`rules:list`, `rules:edit`, `rules:remove` CLI commands**:
  deferred to v2. JSON files are hand-editable; `phpunit` catches
  hand-edit breakage.
- **Bulk rule sharing**: deferred. The committed `available/`
  directory already serves as the canonical shared library; any
  operator adding a rule via `rules:add` can `git diff` to send
  the new rule upstream as a PR.

## Counterparty resolver — BCP edge cases (DD trailing references, PAG BXVAL, LEV ATM)

### What changed

- `app/Services/Counterparty/Resolver.php` — three new BCP-specific
  shape detectors plus one prefix pattern. Fixes payee aggregation
  for direct debits, Via Verde tolls, and ATM withdrawals.

  - **DD direct debits**: new `extractFromDdWithReference()` cuts at
    the trailing 8+ digit customer reference (with an optional
    one-word alpha sub-product token between the reference and the
    creditor id), dropping the reference and the PT/DI creditor
    identifier. Concrete example: `DD GIN CLUBE PORT 00335110554
    PT22100415` → `GIN CLUBE PORT` (was: full string, breaking
    aggregation). Trailing punctuation on the merchant (BCP's
    `EDP COMERCIAL-` artifact) is also trimmed. The merchant
    capture is non-digit (`[^\d]+?`) and the reference threshold
    is 8+ digits so descriptors that embed numbers themselves
    (e.g. `DD ACME 2024 PT12345678`, `DD GYM 1234 PREMIUM 000123
    PT12345678`) fall through to plain DD prefix-stripping rather
    than being mis-cut. SUNSETFITGYM (real BCP merchant with a
    4-digit ref) is accepted as collateral — its rows fall through
    to the noisy form, which is strictly safer than over-merging
    distinct year-suffixed merchants.
  - **PAG BXVAL- (Via Verde tolls)**: new prefix pattern
    `/^PAG\s+BXVAL-\s+\d+\s+/i` so `PAG BXVAL- 5962 VIAVERDE` →
    `VIAVERDE`.
  - **LEV ATM (ATM withdrawals)**: new `extractFromLevAtm()` with
    a location-extraction regex. `LEV ATM 5962 703   LISBOA
    Mario Nunes E` → `ATM LISBOA`. Cardholder echo is dropped — it's
    BCP echoing the account holder, not a real counterparty.
    Defensive fallback: lines starting with `LEV ATM` that don't
    fit the location-extraction shape collapse to bare `ATM`.

  The two new shape detectors run **before** the existing structured-
  CSV (ING RO) extraction and generic prefix-stripping path because
  their cleanup is destructive and shape-aware.

- `tests/Unit/Services/Counterparty/ResolverTest.php` — 16 new
  unit tests. Real-data fixtures from the operator's BCP
  transactions: GIN CLUBE PORT, NOS Comunicaco, OCIDENTAL/MEDIS
  with DI-prefixed identifier (plus an accented MÉDIS variant),
  EDP COMERCIAL with hyphen artifact, the PAG BXVAL Via Verde
  line, and both LEV ATM branches (with-location and bare-
  fallback) including a multi-word location with internal doubled
  spaces. Regression fixtures cover the false-positive shapes
  surfaced across six codex review rounds: DD without creditor-id
  suffix, DD with embedded year/plan codes, DD with numeric
  intermediate token, DD with short reference directly before the
  creditor id, and the SUNSETFITGYM trade-off documenting the
  4-digit-ref fall-through.

### Assumptions made

- **EB lookback cap is 90 days for BCP**: confirmed empirically.
  Bumping `banks.sync_lookback_days` to 730 then 180 both returned
  HTTP 422 from EB; 90 succeeded. PSD2 mandates 90 days post-
  consent without re-auth, and BCP doesn't extend that. The
  337-row dataset is therefore the full available window.
- **`dedup_hash` is independent of `counterparty_name`**: confirmed
  via the `CounterpartyRecomputeCommand` docblock. The hash uses
  `creditor.name` / `debtor.name` from `raw_payload`, not the
  Resolver's L2 remittance extraction. Tuning the L2 path
  doesn't shift dedup hashes for existing rows.
- **All BCP data lands at level 2**: 337/337 rows resolve at L2
  because BCP doesn't populate `creditor.name` / `debtor.name`.
  The new detectors operate exclusively in the L2 path.
- **Production EB session timezone is UTC**: per CLAUDE.md.

### Blast radius

- **`spendula:sync` path**: every fresh transaction gets the new
  resolver. Rows re-fetched within the 90-day window get
  re-resolved on update — observed: a fresh sync against the
  pre-existing dataset produced `inserted=0 updated=18 deduped=297`,
  with the 18 updates rewriting `counterparty_name` in-place
  via the new resolver.
- **`spendula:counterparty:recompute --bank=bcp`**: applied to
  the existing 337-row BCP dataset, changed the one row the
  sync re-fetch hadn't already updated. Distinct BCP payee count
  is now 143; `GIN CLUBE PORT` aggregates 4 transactions, etc.
- **No effect on dedup**: `dedup_hash` derives from raw EB
  fields, not resolver output.
- **No effect on YNAB push**: push reads `counterparty_name` as
  the YNAB payee. After recompute, every GIN CLUBE PORT DD
  lands on one YNAB payee instead of one-per-month.
- **No effect on existing tests**: full suite 241/241 (16 new,
  all green); PHPStan level 8 clean (`--memory-limit=1G`).

### Open threads

- **Other PT banks may share BCP shapes**: patterns match the
  literal `DD `, `LEV ATM`, and `PAG BXVAL-` prefixes, which are
  BCP-specific. If another PT bank uses similar shapes with
  different prefixes those won't be recognised — add patterns
  when concrete data appears (CLAUDE.md "second concrete need"
  rule).
- **Multi-bank LEV ATM normalisation**: the `ATM` payee is
  bank-agnostic by design — a Revolut withdrawal and a BCP
  withdrawal both become `ATM <city>`. The bank is still
  recoverable via `transactions.bank_account_id`.
- **`test_level_2_strips_bcp_dd_prefix`** still asserts
  `DD EDP COMERCIAL  16` → `EDP COMERCIAL  16` (reference too
  short to trigger the new cut). Kept as a regression that
  documents the `\d{4,}` threshold.

## GH issue #18 — Phase 4c: README + ops polish for v1 release

### What changed

- `README.md` — rewritten end-to-end to follow the new-operator
  zero-to-first-push narrative the issue asked for. Sixteen numbered
  sections in fixed order: framing, stack, documentation index,
  prerequisites, first-time setup, sandbox first run, production EB
  recipe, adding real banks to the catalogue, YNAB starting-balance
  vs. import-cutoff gotcha, real-bank flow, tracking accounts (multi-
  currency), weekly ritual, troubleshooting, artisan commands table,
  production deployment, conventions, and the v1-complete §14
  satisfaction footer. Existing prose preserved verbatim where it was
  already correct (the production-EB-against-local-dev recipe, the
  YNAB starting-balance gotcha, the production deploy pointer at
  `docs/DEPLOY.md`); the four new sections are tracking accounts,
  weekly ritual, troubleshooting, and the v1-complete footer.
  Migrations are now ordered before `.env` fill-in so the operator
  can run `php artisan test` early and confirm the toolchain is
  healthy before chasing credential issues. The Mock-ASPSP
  walkthrough uses `spendula:status --include-mock` for the dashboard
  step (bare `status` filters mock rows out by default — #16's
  design); the real-bank walkthrough uses bare `spendula:status`.
- `README.md` artisan commands table — `spendula:status` and
  `spendula:tracking:snapshot` moved out of the Phase-2+ stubs table
  and into the Implemented table. Added rows for
  `spendula:counterparty:recompute` and `spendula:accounts:seed-mock`
  with one-line context. Stubs table now contains only
  `spendula:convert-pending` with a footnote pointing at
  [dlucian/spendula#23](https://github.com/dlucian/spendula/issues/23)
  for the deferred follow-up.
- `README.md` — new "Adding real banks to the catalogue" section
  documents `spendula:banks:add` as the explicit step before any
  real-bank `auth:start`. Includes a fenced `spendula:banks:add`
  example for Millennium BCP plus a tinker recipe for pulling the
  canonical `--aspsp-name` value from `EnableBanking\Client::aspsps()`.
  Without this step `auth:start <real-slug>` hard-fails because
  `config/spendula-banks.php` ships only the `mock` fixture by design.
- `README.md` — new "Tracking accounts (multi-currency)" section
  describes `spendula:accounts:map`'s actual prompt flow (foreign-
  currency bank accounts get only tracking-typed YNAB targets in the
  picker — derived from `on_budget=false`, NOT a fictional
  `on_budget=n` flag) plus the `spendula:tracking:snapshot` workflow
  (suggested cadence: monthly with month-close per SPEC §5.4;
  same-day idempotency; `--account=<spendula-uuid>` per-account
  scope; `--dry-run` to preview deltas).
- `README.md` — new "Weekly ritual" section ships a copy-pasteable
  bash snippet split into two stages: pipeline
  (`sync && review && push`) followed by `spendula:status`
  unconditionally. Documents the exit-code semantics: exit 1 on red
  consent / push-stuck / stale-sync; exit 0 on yellow consent or
  all-clear.
- `README.md` — new "Troubleshooting" section with four sub-blocks:
  consent expired (re-run `auth:start <slug>`), push stuck
  (inspect via `status` then re-run `push`), real-bank consent
  failing on first try (verify EB env via tinker), and the YNAB
  starting-balance gotcha (cross-link to §8). Plus a sub-block for
  `spendula:counterparty:recompute --bank-account-id=<uuid>` with
  fenced example.
- `README.md` — new "v1 complete — SPEC §14 satisfied" footer.
  Twenty-row table mapping each SPEC §14 bullet to the file or
  command satisfying it, plus a short re-verification checklist for
  the v1-release acceptance gates (Mock end-to-end, `php artisan
  test` green, PHPStan level 8 green, Pint clean, prod Docker build
  succeeds). Replaces the stale "Project status" section that
  claimed phases 2–4 were pending.
- `resources/views/banking/callback-success.blade.php` — the post-
  consent next-step recommendation flipped from
  `spendula:accounts:seed-mock --bank-account-id=… --ynab-account-id=…`
  to `spendula:accounts:map` (interactive, prod path). The
  `seed-mock --help` alternative is mentioned for dev / CI scripted
  use, and the `bank_account_id` stays surfaced in the table above
  so the operator can copy it for either path. Keeps the callback
  success page in sync with the README's production walkthrough.
- `docs/SPEC.md` §3.3 — flipped the labels for `spendula:accounts:map`
  ("real in phase 2"), `spendula:status` ("real in phase 4"), and
  `spendula:tracking:snapshot` ("real in phase 3"). Appended a
  parenthetical to the "Every real command acquires an advisory
  lock" sentence carving out `spendula:accounts:map` (idempotent
  UPDATE-LAST-WINS) and `spendula:status` (read-only). Added the
  `dlucian/spendula#23` deferral pointer to the
  `spendula:convert-pending` line.
- `docs/SPEC.md` §14 bullet 20 — appended a parenthetical pointing
  at the README sections (§3 Prerequisites, §6 Production EB
  registration, §11 Troubleshooting wired into §12 of the new
  layout) that satisfy the "Setup README" v1 ship criterion.
- `docs/SPEC.md` §16.4 — items 24 (`spendula:status` dashboard) and
  26 (consent expiry surfacing in `status`) struck through with a
  done-by-#16 marker. Item 25 (`spendula:convert-pending`) gets the
  same `dlucian/spendula#23` deferral parenthetical.
- `docs/PLAN.md` — Phase 4c struck through (`done 2026-05-01,
  GH #18`). Phase 4b (`spendula:convert-pending`) gains the
  `dlucian/spendula#23` deferral parenthetical. The "v1 complete
  when phase 4 ships; SPEC §14 is satisfied" sentence is now the
  operative truth.
- `app/Console/Commands/Spendula/DECISIONS.md` — appended the
  `2026-05-01 — README structure for v1 release (issue #18)` entry.
  Nine decisions covering: incremental rewrite vs. from-scratch,
  section ordering reflecting the new-operator narrative,
  `accounts:seed-mock` retained for tests/CI but retired from the
  prod walkthrough, `convert-pending` documented as deferred via #23
  rather than removed, `spendula:banks:add` as an explicit real-bank
  flow step, weekly-ritual snippet's two-stage shape, mock
  walkthrough's `--include-mock` requirement, callback view edited
  to recommend `accounts:map`, and the SPEC + PLAN docs-sync that
  precedes the README cross-link.

### Assumptions made

- **Mock ASPSP behaviours.** No PSD2 round-trips touched — this is
  a docs change. The Mock-ASPSP behaviours called out in the README
  walkthrough (zero seeded accounts by default, level-1 inverted
  counterparty resolution) are unchanged from prior sessions.
- **YNAB API responses.** No live YNAB hits. The footer's §14 bullet
  9 ("on-budget EUR push") and bullet 10 ("tracking snapshot push")
  point at code paths shipped in earlier phases; this PR doesn't
  add or change YNAB-touching code.
- **OAuth state assumptions.** No callback or session round-trips
  touched. The callback success view edit is a Blade-string change
  with no behavioural impact on the request lifecycle.
- **Postgres session timezone is UTC** during the test run (default
  config). No SQL or migration changes in this PR.
- **External quirks treated as fixed.** SEPA-correct CRDT/DBIT
  counterparty inversion, `identification_hash` stability across
  re-auth, EB pagination via `continuation_key` — all referenced in
  the README footer §14 table without re-litigating; behaviours
  pinned by earlier-phase tests.

### How to verify

- **Dry walkthrough.** Read `README.md` top to bottom on a fresh
  shell and confirm each command runs without external doc lookup:
  prerequisites → setup → sandbox first run → production EB recipe
  → real-bank flow → weekly ritual. Each command in a fenced block
  has expected behaviour described inline.
- **Mock-ASPSP sandbox loop**: every step in §5 runs cleanly,
  `spendula:status --include-mock` renders the mock bank's rows.
- **Real-bank gate**: `spendula:banks:add --slug=<real-slug> …`
  succeeds and the subsequent `spendula:auth:start <real-slug>`
  reaches the EB consent URL where it would otherwise hard-fail.
- **Weekly ritual partial-failure path**: seed a `push_attempt_count
  = 5 AND status = 'approved'` row, run the snippet, confirm
  `spendula:status` is reached and exits 1 even when `spendula:push`
  is a no-op or fails.
- **Callback view sync**: `grep -F 'spendula:accounts:map'
  resources/views/banking/callback-success.blade.php` returns 1
  match (the recommended next step); `grep -F
  'spendula:accounts:seed-mock' resources/views/banking/
  callback-success.blade.php` returns 1 match (the dev-helper
  alternative line, NOT the primary recommendation).
- **SPEC + PLAN docs-sync**: `grep -nE 'stub in phase.*(status|
  tracking:snapshot|accounts:map)' docs/SPEC.md` returns zero matches.
  `grep -nE '4c\. README' docs/PLAN.md` returns the strikethrough
  line.
- **Command coverage**: every command name in
  `app/Console/Commands/Spendula/*.php` appears in at least one
  fenced code block in `README.md`, except `spendula:convert-pending`
  which is documented in the stubs footnote only. Specifically
  `spendula:counterparty:recompute` and `spendula:banks:add` get
  fenced examples in addition to their table rows.
- **Code-quality gates**: `php artisan test` green, `vendor/bin/
  phpstan analyse` level 8 green, `vendor/bin/pint --test` clean.
  No PHP / migration / config changes outside the callback Blade
  string and the docs.

### Out of scope

- **Removing the `spendula:convert-pending` stub command.** Per the
  team-lead's drop-and-defer routing on #17, the stub stays in v1.
  Removing it (plus the matching row in `StubCommandsTest.php`) is
  a separate cleanup PR if ever taken.
- **Cron / systemd timer surface for the weekly ritual.** SPEC §14.1
  defers scheduled sync to v2+. The README documents the snippet as
  a manual trigger only.
- **CLAUDE.md updates.** CLAUDE.md is the session-orientation file
  for future Claude Code runs; its scope is different from the
  README's (operator-facing). Untouched by this PR.
- **Web UI for review.** SPEC §14.1 non-goal. `spendula:review` is
  the v1 approval surface.

---

## GH issue #16 — Phase 4a: spendula:status dashboard

### What changed

- `app/Console/Commands/Spendula/StatusCommand.php` — Phase-1 stub
  replaced with a real dashboard implementation. The command stays
  thin: resolves `App\Services\Status\StatusSnapshotBuilder` and
  `StatusRenderer` from the container, hands `--include-mock` (default
  false) to the builder, renders the snapshot to `$this->getOutput()`,
  and returns `FAILURE` when `$snapshot->hasRedOrStuckRows()`. Class
  body stays under 30 lines.
- `app/Services/Status/Thresholds.php` — new final class centralising
  `CONSENT_YELLOW_DAYS = 14`, `CONSENT_RED_DAYS = 3`,
  `SYNC_STALE_HOURS = 24`, `PUSH_STUCK_ATTEMPTS = 5`. Mirrors SPEC §9.4
  for the consent thresholds.
- `app/Services/Status/StatusSnapshot.php` — new immutable value
  object carrying `banks` (list of `BankRow`), `stuckTransactions`
  (list of `StuckTransactionRow`), `hasRedOrStuckRows`, `isEmpty`,
  and the `generatedAt` Carbon (captured once inside the snapshot
  transaction so renderer day-math is deterministic).
- `app/Services/Status/BankRow.php` — new readonly DTO. Carries slug,
  display name, `bankActive`, `consentValidUntil`,
  `consentDaysRemaining`, `consentStatus` (stored enum or `'none'`),
  `effectiveConsentStatus` (reconciled with `valid_until`),
  `consentWarningLevel` (`green|yellow|red|na`), zero-filled
  `queuedCounts` for `fetched/approved/transfer/tracking`,
  `lastSyncedAt` (sourced from `bank_connections.last_synced_at`),
  `lastPushedAt`, `lastSnapshotAt`, and `syncStale`.
- `app/Services/Status/StuckTransactionRow.php` — new readonly DTO
  for one push-stuck warning row.
- `app/Services/Status/StatusSnapshotBuilder.php` — new service.
  Single public `build(bool $includeMock, ?Carbon $now = null):
  StatusSnapshot`. Wraps four reads in a `DB::transaction()` after
  `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY`. Picks
  one connection per bank via Postgres `DISTINCT ON` (active row
  first, else most-recent by `created_at`). Stuck query is the
  three-way `push_attempt_count >= 5 AND status IN ('approved',
  'transfer') AND ynab_transaction_id IS NULL` so post-success rows
  don't linger.
- `app/Services/Status/StatusRenderer.php` — new service. Pure
  renderer; takes a `StatusSnapshot` plus the command's
  `OutputStyle`. Empty snapshot → "Nothing to show" line. Otherwise
  four sections in fixed order: Consent table → Queued transactions
  table → Last activity table → Push-stuck warnings list (omitted
  entirely when empty). Uses Symfony Console color tags
  (`<fg=yellow>`, `<fg=red>`, `<fg=green>`); piped/non-TTY output
  drops the tags automatically.
- `tests/Feature/Commands/Spendula/StatusCommandTest.php` — new
  feature test. 17 cases: empty DB → exit 0; consent T-15/T-14/T-4/
  T-3/expired threshold transitions; queued counts vs
  `pushed`/`skipped` exclusion; 25h-stale on active consent → exit 1;
  25h-stale on expired consent → no double-warn; stuck at attempts=5
  → exit 1; stuck at attempts=4 → exit 0; stuck filter excludes
  `pushed` rows AND rows with `ynab_transaction_id` set; inactive
  bank carve-out; lazy-expiry reconciliation; just-reauthed bank
  with NULL `last_synced_at` is stale despite stale per-account
  state; `--include-mock` toggle.
- `tests/Unit/Services/Status/StatusSnapshotBuilderTest.php` — new
  unit test covering threshold-level transitions, expired/no-
  connection states, zero-fill of queued counts, lazy-expiry
  reconciliation, the `hasRedOrStuckRows` boolean across all three
  trip sources, inactive-bank exclusion, injected-clock determinism,
  and the just-reauthed/null-last-synced-at case at the
  builder layer.
- `tests/Unit/Services/Status/StatusRendererTest.php` — new unit
  test using `Symfony\Component\Console\Output\BufferedOutput`
  wrapped in a Laravel `OutputStyle` to capture writes.
  Asserts: empty-snapshot friendly message, color-tag emission for
  yellow + red consent in decorated mode, stale annotation, omission
  of warnings section when none, full per-row format of the warnings
  section including the `(unknown)` counterparty placeholder.
- `tests/Feature/Commands/Spendula/StubCommandsTest.php` — removed
  the `'spendula:status'` data-provider entry now that the command
  is no longer a stub.
- `app/Console/Commands/Spendula/DECISIONS.md` — appended the
  2026-05-01 dashboard entry. Ten decisions, covering: snapshot/
  renderer split, four-section fixed order, exclusion of
  `pushed`/`skipped`, gated stale-sync warning, centralised
  thresholds, mock filter at SQL builder, sync-freshness source
  rationale, three-way stuck query, effective-consent
  reconciliation, REPEATABLE READ READ ONLY transaction.
- `docs/PLAN.md` — Phase 4a entry struck through with
  `(done 2026-05-01, GH #16)` and a one-liner pointing to the
  command/service files plus the new DECISIONS entry. Phases 4b
  (`spendula:convert-pending`) and 4c (README/ops polish) remain
  open.

### Why

Phase 4a is the dashboard surface called out in `docs/PLAN.md` Phase
4 and SPEC §9.4. Operators need at-a-glance visibility into consent
expiry windows, how many transactions are waiting in each pipeline
stage per bank, when each bank last completed a successful sync/push/
snapshot, and which transactions have stuck in the push queue. The
exit code makes the command cron-suitable: a non-zero exit gates a
notification when something needs operator attention, with no parsing
of the rendered text required.

### Assumptions made

- **Mock ASPSP behaviours.** No PSD2 round-trips touched; the
  dashboard is a local DB-only read. Mock-bank visibility is gated
  by `--include-mock` so a live operator's daily run hides the
  seeded mock by default.
- **YNAB API responses.** No YNAB calls in this slice. The
  push/snapshot wall-times come from local columns
  (`transactions.pushed_at`, `tracking_snapshots.pushed_at`) that
  the existing pipelines already populate.
- **OAuth state.** Not exercised. The dashboard reads
  `bank_connections.status` and `valid_until` as written by
  `CallbackHandler` and `SyncRunner`; the lazy-expiry reconciliation
  treats `status='active' AND valid_until < now()` as effectively
  expired, which matches the `SyncRunner`-flips-it-on-next-sync
  behaviour.
- **Postgres session timezone is UTC** during the test run (the
  default config). `valid_until`, `last_synced_at`, and friends
  round-trip correctly.
- **External quirks treated as fixed.** SPEC §9.4 thresholds
  (T-14 / T-3) are the source of truth; the 24h sync-staleness
  bound is a Phase 4a-introduced operator-prompt threshold (not in
  the SPEC, recorded in DECISIONS).
- **`bank_account_sync_state` is intentionally not surfaced.** The
  snapshot uses `bank_connections.last_synced_at` instead — that
  field is only stamped on whole-connection success, which is the
  right "freshness" signal. Per-account drill-down is a future
  surface.
- **Stuck-query three-way filter.** `push_attempt_count >= 5 AND
  status IN ('approved','transfer') AND ynab_transaction_id IS
  NULL`. PushRunner increments the counter on the success path too
  (verified in `app/Services/Push/PushRunner.php`), so a two-way
  filter on attempt-count alone would linger forever.

### Blast radius

- **Direct callers of the new services:** only `StatusCommand`. The
  builder/renderer/DTO classes are otherwise unreferenced.
- **DB schema:** no migration. Reads four existing tables (`banks`,
  `bank_connections`, `bank_accounts`, `transactions`,
  `tracking_snapshots`).
- **Existing commands:** `StubCommandsTest` data provider trimmed
  by one row. No other command, service, or model touched.
- **Concurrency:** the new `REPEATABLE READ READ ONLY` transaction
  is short-lived (four small aggregates over single-operator-scale
  data). No new advisory lock — there are no writes in this path.
- **Cron exit code:** `0` only when no red/expired consent on an
  active bank, no sync-stale on an active-bank-with-active-consent,
  and no push-stuck rows. Operators on idle should mute the cron
  job rather than soften the threshold (documented in DECISIONS as
  intentional).

### Out of scope

- Web UI, sparklines, push-error history beyond the latest message,
  trend data, drill-down commands.
- Per-account sync drill-down (`bank_account_sync_state` surfacing).
- Phase 4b (`spendula:convert-pending`) and Phase 4c (README/ops
  polish) — both can ship independently.

---

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
