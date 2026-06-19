# Latest task summary

## FX own-account moves reversed to transfers (GH #14 follow-up)

### What changed

- **`app/Services/Sync/MatchUpdateOrInsert.php`** — collapsed FX and same-currency
  own-account branches: both now set `counterparty_name = "Transfer : <dest>"` and
  `ownAccountTransfer = true`. Added `buildFxSuffix()` private helper that appends
  `[FX] <orig_amount> <orig_ccy> @ <rate>` to the remittance when the EB payload
  carries a `currency_exchange.instructed_amount` + `currency_exchange.exchange_rate`
  object (SPEC §5.6). No fabrication when the field is absent.
- **`app/Console/Commands/Spendula/CounterpartyRecomputeCommand.php`** — mirrored the
  same collapse: FX and same-currency both get the Transfer prefix and the
  `ownAccountTransfer` flag; status promotion to `transfer` now fires for both.
- **`app/Services/Counterparty/OwnAccountClassification.php`** — updated `$sameCurrency`
  docblock to reflect the new caller behavior (both values are now transfer; the flag
  is kept for optional memo-enrichment use).
- **`config/spendula.php`** — removed `own_account.fx_payee` key and the associated
  comment. Updated section comment to reflect FX = transfer.
- **`.env.example`** — removed `SPENDULA_OWN_ACCOUNT_FX_PAYEE` and its comment.
- **`tests/Feature/Services/Sync/MatchUpdateOrInsertTest.php`** — renamed and updated
  the FX test (`test_own_account_different_currency_dbit_free_text_classified_as_transfer`):
  now asserts `status=transfer`, `counterparty_name="Transfer : ING SRL RON"`,
  `amount_milliunits = -235000`. Added two new tests: FX with `currency_exchange` payload
  (asserts `[FX]` suffix in remittance), FX without it (asserts no `[FX]` suffix).
- **`tests/Feature/Commands/Spendula/CounterpartyRecomputeCommandTest.php`** — renamed
  same-currency test to `test_recompute_promotes_fetched_own_account_same_currency_to_transfer_status`.
  Added `test_recompute_promotes_fetched_fx_own_account_to_transfer_status`: seeds a
  "Currency Exchange" row with RON destination, asserts recompute promotes to transfer
  and renames to "Transfer : ING SRL RON".
- **`DECISIONS.md`** — appended 2026-06-19 ADR entry documenting the reversal and why.
- **`CHANGELOG.md`** — updated GH #14 bullet to reflect FX = transfer and the
  `[FX]` memo enrichment; noted `SPENDULA_OWN_ACCOUNT_FX_PAYEE` removal.

### Assumptions made

- The EB `currency_exchange` payload field is present only when the bank populates it
  (per SPEC §5.6 — "populated by some banks for cross-currency transactions"). ING-RO
  free-text own-account transfers do not carry it; no fabrication occurs in those cases.
- The `currency_exchange.instructed_amount.{amount, currency}` and
  `currency_exchange.exchange_rate` sub-fields are the relevant EB schema fields based
  on SPEC §5.6 wording and the Enable Banking API conventions. No live FX payload was
  available to confirm against; the helper returns null on any missing/malformed field
  rather than fabricating data.
- `OwnAccountClassification.sameCurrency` is retained (not removed) — it is still
  meaningful for optional memo-enrichment branching and removing it would be a larger
  API change with no benefit at this scale.

### Blast radius

- **Sync behavior change:** FX own-account moves that previously landed as
  `status=fetched` / `counterparty_name="Currency Exchange"` now land as
  `status=transfer` / `counterparty_name="Transfer : <dest>"`. Already-synced rows
  are NOT retroactively updated on re-sync (status is immutable after insert).
- **Backfill:** `spendula:counterparty:recompute` will promote existing FX own-account
  rows at status=fetched to transfer. Approved/skipped/pushed rows are invariant.
- **Config change:** `SPENDULA_OWN_ACCOUNT_FX_PAYEE` is no longer read. Operators who
  set it in `.env` must remove it; leaving it in place is harmless (key gone from config).

### Open threads

- Already-pushed FX own-account rows ("Currency Exchange" payee in YNAB) must be
  corrected manually in YNAB. The historical push stands via YNAB's `import_id`
  deduplication; `spendula:counterparty:recompute` fixes the local DB row only.

---

## Own-account classifier — codex review round 1 edge-case fixes (GH #14)

### What changed

- **`app/Services/Counterparty/OwnAccountClassifier.php`** — two fixes:
  1. **(Major 1) Ambiguity guard moved before source exclusion.** Count of active accounts matching the normalized IBAN is now checked *before* filtering out the source. If count ≠ 1, return null immediately. If count == 1 and sole candidate is the source (self-transfer), return null. Fixes the prior bug where source + one other active account sharing an IBAN would produce count == 1 after source exclusion and wrongly classify the other account.
  2. **(Major 2) Spaced-IBAN regex.** Free-text patterns `"To account,"`/`"From account,"` now capture `[A-Z0-9 ]+?` (spaces allowed) with lookahead `(?=\s*,|\s*$)`. `normalizeIban()` strips spaces before the DB lookup.
- **`app/Services/Counterparty/OwnAccountClassification.php`** — **(Minor)** new `destinationLabel(): string` method. Returns `trim(display_name)` when non-blank, else falls back to `destinationIban`. Prevents `"Transfer : "` when `display_name` is null/empty/whitespace.
- **`app/Services/Sync/MatchUpdateOrInsert.php`** — **(Minor)** use `$classification->destinationLabel()`.
- **`app/Console/Commands/Spendula/CounterpartyRecomputeCommand.php`** — **(Minor)** same call-site fix.
- **`tests/Feature/Services/Counterparty/OwnAccountClassifierTest.php`** — four new tests: Major 1 regression guard (source + another active account share IBAN → null), Major 2 DBIT spaced IBAN, Major 2 CRDT spaced IBAN, Minor blank display_name falls back to IBAN.
- **`tests/Unit/Services/Counterparty/ResolverTest.php`** — two new tests (Major 3, test-only): external beneficiary resolves to itself NOT "BUGETUL DE STAT"; unparseable remittance resolves to own text NOT "BUGETUL DE STAT".

No migrations, no new commands, no new routes. Full suite 477/477.

### Assumptions made

- Postgres session timezone is UTC during test run. Tests run against real Postgres `spendula_test`.
- "BUGETUL DE STAT" mislabeling confirmed YNAB-side auto-match (prior session verified via `git log -p --all -S "Stat RO"` returning empty). Major 3 tests codify this invariant.

### Blast radius

- `OwnAccountClassifier::classify()`: behavioral change only for source + another active account sharing an IBAN (was wrongly classified, now null).
- `MatchUpdateOrInsert` and `CounterpartyRecomputeCommand`: behavioral change only when `display_name` is blank/whitespace (was "Transfer : ", now uses IBAN as suffix).

### Open threads

- Rows already pushed with blank display_name have "Transfer : " in YNAB; operator can run `spendula:counterparty:recompute` locally and rename in YNAB manually.

---

## Own-account transfer/FX classifier (GH #14)

### What changed

- **`app/Services/Counterparty/OwnAccountClassification.php`** (new) — immutable DTO
  returned when a transaction destination IBAN matches an own account.
- **`app/Services/Counterparty/OwnAccountClassifier.php`** (new) — DB-aware service.
  Extracts destination IBAN from the EB transaction (structured `creditor_account.iban`
  / `debtor_account.iban` first, then free-text "To account," / "From account,"
  fallback, direction-aware per CDI). Matches against a normalized-IBAN map of active
  `bank_accounts`, excluding the source account. Returns null for external, ambiguous
  (duplicate active IBAN), inactive, or self-transfer cases.
- **`app/Services/Sync/ParsedIncomingTransaction.php`** — added `bool $ownAccountTransfer`
  (default false). True only for same-currency own-account inserts.
- **`app/Services/Sync/MatchUpdateOrInsert.php`** — injected `OwnAccountClassifier`;
  calls `classify()` after `resolve()` in `parseIncoming()`. For same-currency own-account:
  overrides `counterpartyName` to `"<prefix> : <dest display_name>"` and sets
  `ownAccountTransfer = true`. For FX: overrides name to `fx_payee`. `insert()` status
  match extended: cutoff → Skipped, Tracking → Tracking, `ownAccountTransfer` → Transfer,
  else → Fetched.
- **`app/Console/Commands/Spendula/CounterpartyRecomputeCommand.php`** — injected
  `OwnAccountClassifier` via method injection. Applies same name override after
  `resolve()`. Promotes `fetched → transfer` for same-currency own-account rows only;
  never touches approved/skipped/pushed/tracking/transfer. Honors `--dry-run`.
- **`config/spendula.php`** — added `own_account.transfer_prefix` (env
  `SPENDULA_OWN_ACCOUNT_TRANSFER_PREFIX`, default "Transfer") and `own_account.fx_payee`
  (env `SPENDULA_OWN_ACCOUNT_FX_PAYEE`, default "Currency Exchange").
- **`.env.example`** — added `SPENDULA_OWN_ACCOUNT_TRANSFER_PREFIX` and
  `SPENDULA_OWN_ACCOUNT_FX_PAYEE` entries with comments.
- **`tests/Feature/Services/Counterparty/OwnAccountClassifierTest.php`** (new) — 12
  feature tests covering DBIT/CRDT free-text, structured field priority, null cases
  (no IBAN, external IBAN, inactive, self-transfer), duplicate-IBAN ambiguity, and
  `normalizeIban`.
- **`tests/Feature/Services/Sync/MatchUpdateOrInsertTest.php`** — 10 new cases:
  same-currency DBIT transfer, FX DBIT, external beneficiary no override, unparseable
  remittance (no-mislabel regression), structured-iban priority, self-exclusion,
  inactive account, duplicate IBAN ambiguity, CRDT free-text, pre-cutoff skipped.
- **`tests/Feature/Commands/Spendula/CounterpartyRecomputeCommandTest.php`** — 3 new
  cases: backfill promotes fetched→transfer, no status mutation for approved, dry-run
  no-write. Updated `seedTransactionFor` to accept optional `$status` parameter.
- **`DECISIONS.md`** — GH #14 entry (own-account classifier design, YNAB mislabel
  forensics, SPEC §8 FX divergence, alternatives considered).
- **`CHANGELOG.md`** — new file, "Fixed" entry for GH #14.
- **`SUMMARY.md`** — this file.

No migrations. No new artisan commands. No new HTTP routes.

### Assumptions made

- `bank_accounts.iban` is nullable and NOT unique (schema confirmed — no unique index
  exists; the duplicate-active-IBAN guard is load-bearing).
- The ING-RO free-text format "To account, <IBAN>" and "From account, <IBAN>" is
  the real production format for rows whose `creditor_account` / `debtor_account`
  structured fields are null. This matches the 13 problem rows seen by the operator.
- YNAB deduplicated the 13 already-pushed rows by `import_id` — the historical
  "Bugetul de Stat RO" YNAB payee was a YNAB-side fuzzy auto-match, not a value
  Spendula produced. Confirmed by `git log -p --all -S "Stat RO"` returning empty.
- Postgres session timezone is UTC (per `config/database.php` default).
- Tests run against real Postgres `spendula_test`.

### Blast radius

- **`MatchUpdateOrInsert`** constructor signature changed: new required `OwnAccountClassifier`
  parameter added. Any direct instantiation (outside the IoC container) must pass it.
  Affected: the test setUp (updated), and any future test that constructs `MatchUpdateOrInsert`
  directly. Commands that resolve it from the container are unaffected (Laravel
  auto-resolves `OwnAccountClassifier` since it has no constructor dependencies).
- **`CounterpartyRecomputeCommand::handle()`** now promotes `fetched → transfer` for
  same-currency own-account rows. Running `spendula:counterparty:recompute` on an
  existing DB with pre-existing own-account rows at status=fetched will change their
  status. This is intentional and is the primary purpose of the backfill command for
  this issue. Pre-cutoff/approved/skipped/pushed/tracking rows are invariant.
- **`ParsedIncomingTransaction`** has a new optional constructor parameter
  `$ownAccountTransfer`. Any direct construction outside the MatchUpdateOrInsert parser
  (e.g., test helpers that build `ParsedIncomingTransaction` directly) must be audited;
  since it has a default of `false`, existing callers compile without modification.

### Open threads

- Already-pushed own-account rows (the 13 historical ones) must be corrected in YNAB
  by hand. `spendula:counterparty:recompute` updates the local DB row's `counterparty_name`
  and `status`, but YNAB deduplicates on `import_id` — the historical YNAB transaction
  stays under the old payee.
- YNAB's native transfer pair (`transfer_account_id`) is explicitly out of scope for
  v1 (SPEC §8). The `status=transfer` + `[TRANSFER]` memo convention is the v1 bridge;
  the operator converts to a native pair manually in YNAB.
- Banks other than ING-RO that encode own-account transfers differently (e.g., via a
  non-standard `internal_transfer_id` field) will not be detected by the current
  free-text patterns. Extend `OwnAccountClassifier::extractDestinationIban()` when a
  second bank's format is confirmed.
- The CRDT "From account," pattern is implemented and tested against a synthetic fixture;
  it has not been observed against a live ING-RO CRDT own-account transfer (CRDT moves
  go to the destination account's sync, which may not be in `spendula_dev` yet). The
  Mock ASPSP does not exercise this path in the current fixture set.

---

## Out-of-band rule install: `spendula:rules:add` command (GH #8)

### What changed

- **`app/Console/Commands/Spendula/RulesAddCommand.php`** (new) — artisan command
  `spendula:rules:add {bank_slug} {counterparty_name} {action} [--reason=] [--force]`.
  Validates inputs (action in {approve,skip,transfer}, --reason only with skip,
  bank exists, counterparty non-blank), delegates to `PayeeRuleRecorder::recordDirect()`,
  prints `Rule added: <id> <bank_slug> <counterparty_name> <action>` on success.
- **`app/Services/Review/PayeeRuleRecorder.php`** — new `recordDirect()` public
  method (insert with optional force-overwrite, denylist guard, no resolution-level
  guard). `isOnDenylist()` promoted from `private` to `public`.
- **`app/Services/Review/RecordResult.php`** — new `Updated` case returned only by
  `recordDirect()` when `$force = true` overwrites an existing rule. `record()` is
  unchanged.
- **`tests/Feature/Commands/Spendula/RulesAddCommandTest.php`** (new) — 10 feature
  tests covering create, skip+reason, reason-on-non-skip rejection, denylist guard,
  AlreadyExists without --force, force overwrite, unknown bank slug, unknown action,
  blank counterparty, operator-name denylist.
- **`DECISIONS.md`** — appended GH #8 entry (two-entry-point design, alternatives,
  consequences).

### Assumptions made

- Real Postgres is up for the feature tests (`spendula_test` per `phpunit.xml`).
- No `payee_rules` data migration needed — schema unchanged from GH #39.
- `RecordResult` consumers (`ReviewSession`, `PayeeRuleRecorderTest`) verified by
  grep to use no exhaustive `match`; adding `Updated` is safe.
- No advisory lock required — matches `rules:list` / `rules:delete` precedent.
- Postgres session timezone is UTC during the test run (config baseline).

### Blast radius

- `payee_rules` now has a second write surface. `PayeeRuleEngine` reads rules from
  the same table during sync/review — a bad `--force` overwrite can change the
  auto-apply verdict for every future transaction from that counterparty. The
  denylist guard is the safety net; rollback is `spendula:rules:delete <id>`.
- `RecordResult` gains a fourth case (`Updated`). Any future exhaustive-match
  caller will get a PHPStan error rather than a silent wrong branch.
- `isOnDenylist()` is now `public`; callers can probe it directly. No behaviour
  change at existing call sites.

### Open threads

- Bulk import from file (multiple rules in one command invocation) — out of scope.
- Editing an existing rule's counterparty_name — out of scope (use delete + add).
- No separate "Rule updated:" output line for `--force` overwrites — single
  `Rule added:` format kept for script-consumer simplicity.

---

## spendula:accounts:deactivate command + inactive-account invariant (GH #4)

### What changed

- `app/Console/Commands/Spendula/AccountsDeactivateCommand.php` (new) — new artisan command `spendula:accounts:deactivate --id=<uuid> [--force]`. Validates UUID before any DB query, refuses already-inactive accounts and unpushed-without-force, prompts with account summary table, executes a conditional `UPDATE bank_accounts SET active=false WHERE id=? AND active=true` inside `DB::transaction`. Interactive picker (TTY only) lists active accounts with full-UUID labels for collision safety. Comprehensive docblock per CLAUDE.md §"Behavioural contracts".
- `app/Services/Push/PushRunner.php` — added `->where('active', true)` to the inner subquery that builds the candidate bank-account-id set. Inactive accounts' approved/transfer rows are now excluded from YNAB push.
- `app/Services/Status/StatusSnapshotBuilder.php` — added `->where('bank_accounts.active', true)` to `loadQueuedCounts` and `->where('bank_accounts.active', true)` to `loadStuckTransactions`. Deactivated accounts' rows no longer inflate queued counts or appear in the stuck-transactions panel.
- `tests/Feature/Commands/Spendula/AccountsDeactivateCommandTest.php` (new) — 13 tests covering scripted path, UUID validation, already-inactive refusal, unpushed-guard, --force, sibling-table immutability, confirmation-declined, interactive picker with active-only filter, full-UUID uniqueness, cancel, no-active-accounts, and --no-interaction guard.
- `tests/Feature/Push/PushRunnerInactiveAccountTest.php` (new) — 2 tests: inactive account's rows not pushed, active sibling in same run still pushes.
- `tests/Feature/Status/StatusSnapshotBuilderInactiveAccountTest.php` (new) — 2 tests: queued counts exclude inactive accounts, stuck transactions exclude inactive accounts.
- `docs/SPEC.md` — added `spendula:accounts:deactivate` to the command list and advisory-lock carve-outs; noted the inactive-account exclusion in PushRunner/status semantics.
- `README.md` — added command-table row and updated feature list entries 13 and 16.

### Assumptions made

- `bank_accounts.active` is the single quarantine lever for sync/push/status (re-verified at `SyncRunner.php:155`, `PushRunner.php:67`, `StatusSnapshotBuilder.php:201 & 283`); `SyncRunner` already filtered on `active` at line 155.
- `TransactionStatus::Approved` and `Transfer` are the only "unpushed but operator-decided" statuses; `Fetched` is pre-review and excluded from the unpushed count intentionally.
- Sibling tables (`bank_account_sessions`, `bank_account_sync_state`, `bank_account_identifiers`, `transactions`) are safe to leave behind — no `ON DELETE` cascades fire from a `bank_accounts.active` flip (it is not a delete). Rows survive for audit and reversibility.
- The Postgres session timezone was UTC during the run (per `config/database.php`).
- YNAB API responses in the PushRunner inactive-account tests were faked using `Http::fake()` with a computed `DedupHasher::importId`; no live YNAB calls were made.

### Blast radius

- `PushRunner`: existing tests that seed `active=true` accounts are unaffected. Existing `test_tracking_accounts_are_skipped` test uses a tracking-type account (not inactive) — unaffected.
- `StatusSnapshotBuilder::loadQueuedCounts` and `::loadStuckTransactions`: existing tests that seed accounts with `active=true` (default) are unaffected. Existing `test_has_red_or_stuck_rows_true_when_stuck_transaction_present` uses `active=true` — verified still passing.
- `SyncRunner` (line 155): already filters on `active` — no change needed, no blast radius.
- `spendula:accounts:map` candidate query (`->where('active', true)`, unchanged) — unaffected.
- Other consumers of `bank_accounts` (`MatchUpdateOrInsert`, `PayeeRuleEngine` eager loads) operate on already-fetched rows, not on an `active=true` invariant — unaffected.

### Open threads

- `spendula:accounts:reactivate` deferred; one-line SQL `UPDATE bank_accounts SET active=true WHERE id=?` is the manual path today.
- A connection whose every mapped account is inactive still produces `accountsAttempted = 0`, so `SyncRunner` line 224's `last_synced_at` stamp never fires and `spendula:status` continues to show the connection as stale. The practical case (the Revolut RON fix) has an active EUR sibling, so the stamp fires correctly. Documented as a known follow-up in the command docblock.
- Race against an in-flight `spendula:push` that has already loaded its candidate set: one push run may still POST an inactive account's rows. Subsequent runs honour the invariant. Documented in the command docblock.

---

## Surface EB/YNAB error bodies (GH #2)

### What changed

- `app/Services/Errors/ErrorDetailFormatter.php` (new) — shared helper that builds the string persisted into `sync_run_errors.error_detail` and `push_run_errors.error_detail`. Output is the existing exception message followed by `\n\nResponse: <json>` when the exception carries a non-null `body`. Truncation at 1000 chars happens AFTER appending so the prefix is preserved.
- `app/Services/Sync/SyncRunner.php:logError` and `app/Services/Push/PushRunner.php:logError` — now call the formatter instead of a naked `substr($e->getMessage(), 0, 1000)`. No schema change: `error_detail` is already `text`.
- `app/Services/Status/RecentErrorRow.php` (new) — DTO carrying one row in the new "Recent sync/push errors" panel.
- `app/Services/Status/StatusSnapshot.php` — adds `recentErrors` field (defaulted to `[]` so existing call sites in tests continue to compile).
- `app/Services/Status/StatusSnapshotBuilder.php` — adds `loadRecentErrors()` inside the existing `REPEATABLE READ READ ONLY` transaction; joins `sync_run_errors` / `push_run_errors` to `bank_accounts → banks`, applies the same mock-bank filter as the rest of the builder, caps at `Thresholds::RECENT_ERRORS_LIMIT` rows within `Thresholds::RECENT_ERRORS_WINDOW_HOURS`.
- `app/Services/Status/Thresholds.php` — adds `RECENT_ERRORS_WINDOW_HOURS = 24` and `RECENT_ERRORS_LIMIT = 10`. The 24h window mirrors `SYNC_STALE_HOURS` so the dashboard has one freshness constant.
- `app/Services/Status/StatusRenderer.php` — adds `renderRecentErrors()`, suppressed when the snapshot's `recentErrors` is empty. The renderer collapses the `\n\nResponse: ` marker into ` — Response: ` so multi-line `error_detail` strings fit on one row; truncates the cell at 120 chars.
- `app/Console/Commands/Spendula/SyncCommand.php` and `PushCommand.php` — when `result->errors > 0`, print one line per error from the run after the summary line. Same single-line shape as the renderer panel.
- Tests: new `tests/Unit/Services/Errors/ErrorDetailFormatterTest.php` (8 cases). `tests/Unit/Services/Status/StatusSnapshotBuilderTest.php` gains 5 cases for the panel (window, cap, mock-filter, null-account fallback, basic load). `tests/Unit/Services/Status/StatusRendererTest.php` gains 3 cases. `tests/Feature/Services/Sync/SyncRunnerTest.php` + `tests/Feature/Services/Push/PushRunnerTest.php` extended to verify the persisted body lands in `error_detail` AND that the inline command-error-tail is printed only on failure (via `Artisan::call/output`).
- `app/Services/Sync/DECISIONS.md` — new entry documenting the `error_detail` format change (prefix preserved, body appended, truncate after append).

### Assumptions made

- `EnableBankingException::$body` and `YnabException::$body` are already populated by `Client.php:safeJson($response)` on the EB side and `Client.php:safeJson($response)` on the YNAB side. The formatter consumes whatever they store; if EB / YNAB ever change response shapes, the DB just stores the new shape verbatim.
- The existing 1000-char `substr` cap is intentional and matches `error_detail` column widths used in psql output. Kept rather than raised.
- Stored rows that landed before this change continue to display just their original prefix (no retroactive backfill). New rows from this point forward carry the body.
- Tests assume `Artisan::call('spendula:sync' | 'spendula:push')` captures `$this->line()` output via `Artisan::output()` — verified.

### Blast radius

- `sync_run_errors.error_detail` and `push_run_errors.error_detail` text contents change shape (longer when an upstream body is available). Any downstream consumer that pattern-matches the literal exception message as a prefix continues to match — only the suffix is new.
- `StatusSnapshot` adds an optional constructor field. Defaults to `[]`; existing tests/callers that don't pass it keep working.
- `spendula:status` output gains one new panel between "Last activity" and "Push-stuck transactions". `--include-mock` honored. Exit code unchanged (the panel is informational only — it does not feed `hasRedOrStuckRows`).
- `spendula:sync` / `spendula:push` printed output gains a per-error tail when `errors > 0`. Exit codes unchanged; clean runs unchanged.

### Open threads

- The renderer's 120-char detail truncation is tuned for one operator terminal width. If the EB / YNAB envelopes ever start exceeding that and the trailing `…` becomes useless, either bump the cap or layer structured columns (out of scope here).
- The Revolut RON HTTP 400 from the 2026-05-18 incident is still unresolved as of this PR. Once merged and deployed, the next `spendula:sync` failure for that account will persist the EB body and we can diagnose without instrumentation.

---

## Sync PDNG-filter field-name fix (GH #46)

### What changed

- `app/Services/Sync/SyncRunner.php:344-352` — the non-BOOK pre-parse filter now reads `$ebTransaction['status']` (EB's actual field name) instead of `$ebTransaction['transaction_status']`. PDNG / INFO / OTHR / FUTR rows now correctly skip via the existing `continue;` branch before reaching `MatchUpdateOrInsert::apply()`. Extended the surrounding comment to spell out the EB-field vs DB-column distinction so the next reader doesn't repeat the mistake.
- `app/Services/Sync/MatchUpdateOrInsert.php:172-181` — same field-name change for the derivation that persists into the DB column `transactions.transaction_status`. The DB column keeps its legacy name (kept to avoid a rippling migration). Default still falls back to `'BOOK'` for banks that omit the field entirely on booked rows.
- `tests/Feature/Services/Sync/MatchUpdateOrInsertTest.php:63` (sampleTransaction helper) and `tests/Feature/Services/Sync/SyncRunnerTest.php:328` (eurTransaction helper) — EB-payload-shape fixtures updated to `status`. The other ~10 `transaction_status` hits across tests/ are `Transaction::query()->create([...])` DB-column inserts and stay unchanged (plan estimated 3 EB-shape sites; actual is 2 — the `MatchUpdateOrInsertTest.php:152` site is a DB insert, not an EB shape).
- `tests/Feature/Services/Sync/SyncRunnerTest.php` — new regression `test_non_book_rows_are_filtered_pre_parse_and_do_not_abort_sync`: a single EB page mixing `status=PDNG` (no booking_date, mimicking ING's card-hold shape), `status=INFO`, missing-`status`, empty-string `status`, and `status=BOOK` rows. Asserts only the BOOK row plus the rows with missing or empty `status` land (empty/missing values persisting as BOOK; entry_refs verified by name), `sync_runs.error_count = 0`, `bank_account_sync_state.consecutive_failure_count = 0`, `last_successful_sync_at` populated, `last_continuation_key` cleared.
- `docs/SPEC.md` §4 (transactions schema) — added a one-clause note on the `transaction_status` row clarifying that it mirrors EB's `status` payload field. §6.2 — corrected the field name in the §6.1 step list, expanded the `{BOOK, PDNG, INFO, …}` set to include OTHR/FUTR, and added a blockquote note pinning the EB-field vs DB-column distinction explicitly.
- `app/Services/Sync/DECISIONS.md` — new 2026-05-11 entry documenting why the bug stayed dormant (BCP/Revolut don't surface PDNG in their AIS feed), the discovery context (discovered during a prod rollout — an ING Romania business EUR account parse_error every sync run, 0 transactions ingested), the decision to keep the DB column named `transaction_status` rather than rename, and the choice of in-place filter fix rather than persist-then-filter (the existing comment at SyncRunner:354-361 explains the constraint: EB does not allow continuation-key replay).

### Assumptions made

- EB's payload schema for the booked/pending status is `status`, consistent across BCP, Revolut LT, and ING Romania. Verified live against an ING Romania business EUR account via the `EnableBanking\Client::accountTransactions()` tinker probe; verified stored against BCP (6 sample rows, all `raw_payload->>'status' = 'BOOK'`, no `transaction_status` key present).
- All currently-stored prod rows have `transaction_status = 'BOOK'` (set by the parser's pre-fix default fallback). No backfill is needed: code in `app/` doesn't branch on `transaction_status` for stored rows (verified via grep), and any future feature that needs the genuine EB value can read `raw_payload->>'status'`.
- Pre-cutoff rows that get `status = Skipped` during sync are unaffected by this change — the filter runs after the BOOK/non-BOOK gate.
- Postgres session timezone was UTC during the test run (config baseline; not specific to this PR).

### Blast radius

- `app/Services/Sync/SyncRunner::syncAccount()` and `app/Services/Sync/MatchUpdateOrInsert::parseIncoming()` — the only two production reads of the field. Both call sites covered by the existing test suite plus the new regression.
- Stored data: zero change to existing rows. The DB column `transactions.transaction_status` keeps its name and values.
- Behavioral surface: BOOK rows ingest unchanged; rows that were silently mis-ingested as BOOK because of the broken filter (none observed; banks emit BOOK for booked rows) would now route correctly; non-BOOK rows that previously aborted sync (only observed for an ING Romania business EUR account on prod) now filter cleanly.
- Downstream: no impact on `:review`, `:push`, `:counterparty:recompute`, `:tracking:snapshot`, `:status`, or `:rules:list/add/delete` — none read `raw_payload->>'transaction_status'` or branch on the column for routing.

### Open threads

- The DB column `transactions.transaction_status` is now permanently misnamed relative to EB's schema. Rename is a follow-up if it ever becomes worth the migration cost; for now the SPEC §4 note plus inline docblocks should keep the next reader out of trouble.
- PDNG → BOOK recovery: when a previously-filtered PDNG card hold transitions to BOOK in a later EB poll, it re-appears within the 7-day overlap window (SPEC §6.2) with `booking_date` populated and gets ingested via the normal match-update-or-insert path. No special-case code in this PR.
- Stored rows on the prod ING Romania business EUR account (currently 0) will land on the next `:sync` post-deploy. Operator demo block in the GH #46 issue body covers the verification path.

---

## ATM cash withdrawal short-circuit in the resolver (GH #42)

### What changed

- `app/Services/Counterparty/Resolver.php` — new structural short-circuit at the top of `resolve()`. When `credit_debit_indicator = "DBIT"` AND `bank_transaction_code.code = "ATM"` (case-insensitive, defensive against missing/non-string code values), return `ResolvedCounterparty(mb_substr($atmCashLabel, 0, 64), 1)` before the L0/L1 name lookup runs. New private helper `bankTransactionCode()` reads the upper-cased code or returns null. Class docblock extended to document the pre-L0 branch.
- `Resolver` constructor gains a third parameter `string $atmCashLabel = 'ATM Cash'` (default for direct test instantiation).
- `app/Providers/AppServiceProvider.php` — registers `Resolver` as a singleton, threading `(string) config('spendula.resolver.atm_cash_label', 'ATM Cash')` into the constructor. Pattern matches the existing `EnableBankingClient` binding.
- `config/spendula.php` — adds `resolver.atm_cash_label` reading `env('SPENDULA_ATM_CASH_LABEL') ?: 'ATM Cash'` so a `cp .env.example .env` flow that leaves the var empty still falls back to the default instead of resolving to `''`.
- `.env.example` — documents the new env var.
- `tests/Unit/Services/Counterparty/ResolverTest.php` — 13 new ATM-related tests (61 total `test_*` methods in class, all green): DBIT+ATM with debtor name set; DBIT+ATM with both names null; DBIT+ATM with non-empty remittance; DBIT+`code: 'atm'` (case-insensitive); CRDT+ATM falls through; DBIT+CARD falls through; missing `bank_transaction_code` falls through; non-string `code` value falls through; constructor-injected label override; ATM short-circuit still validates bank rules (broken rule file fails fast); blank label falls back to default; whitespace-padded label is trimmed before storage (Copilot review PR #44); 64-char truncation on a long label.
- `docs/SPEC.md` §6.8 — counterparty ladder gains a leading "ATM short-circuit" bullet.
- `DECISIONS.md` — appended a 2026-05-08 entry covering the choice of universal resolver branch over rule-schema extension, level 1 over a new level number, deferred location extraction and per-bank label override.

### Assumptions made

- ISO 20022 `bank_transaction_code.code = "ATM"` reliably marks DBIT cash withdrawals at the banks Spendula touches today (Revolut LT confirmed; Mock ASPSP and other banks unverified — they fall through harmlessly if they emit a non-ATM code).
- A single global synthetic label is fine for v1. If a second bank emits divergent ATM semantics, the config key widens from `string` to `string|array<bank_slug,string>` without a migration.
- Backfill is the operator running `spendula:counterparty:recompute` after deploy. The command was already in production for tuning the resolver; no command-level changes needed.
- `Resolver` is now wired through `AppServiceProvider` rather than being container-auto-resolvable. Two existing call sites (`MatchUpdateOrInsert`, `CounterpartyRecomputeCommand::handle`) take `Resolver` via type-hinted DI and continue to work via the singleton binding.
- Postgres session timezone was UTC during the test run; tests are pure unit-level and do not touch the DB.

### Blast radius

- `Resolver::resolve()` only — no other counterparty / sync / push / review code paths change.
- All ATM rows currently in `transactions` re-resolve to the synthetic label after `spendula:counterparty:recompute`. The `dedup_hash` for those rows depends on `normalized_counterparty`, so the hash will change — but the dedup invariant still holds because `normalized_counterparty` is recomputed from the current `counterparty_name` on the next match-or-insert pass, and the existing rows are matched by stored `entry_reference` + `bank_account_id` + `booking_date` + `amount` (per `MatchUpdateOrInsert`), not by `dedup_hash`. Hash drift on already-stored rows is inert.
- GH #39 `payee_rules` table: the synthetic label `"ATM Cash"` becomes a stable rule key. Operators who have already approved/skipped a Spotify-shaped rule for `"JANE DOE"` will have that rule become stale (the row no longer resolves to that name). They can `spendula:rules:list` and `:delete` the orphan if it bothers them; otherwise it sits inert.
- `ReviewSession`, `TransactionActions`, `PayeeRuleEngine`, `PayeeRuleRecorder`: untouched.

### Open threads

- Rule-schema extension (the issue's option b) — still desirable for cases that need name + remittance simultaneous predicates (e.g. some bank's "ATM" code is sometimes a self-transfer, distinguishable only by remittance shape). Tracked implicitly via DECISIONS GH #33's open thread; will become urgent if a real case appears.
- Per-bank `atm_cash_label`. Deferred until a second bank shows divergent ATM behaviour.
- Location extraction from `Cash at <street>` remittance. Deferred; the single stable label is the v1 contract.
- CRDT cash-deposit-at-ATM is currently *not* short-circuited (falls through to normal ladder). If real-world data shows operators want a `"ATM Cash Deposit"` synthetic payee for that direction, widen the branch symmetrically.

---

## Review keystroke modifier: uppercase to decide once without remembering (GH #41)

### What changed

- `app/Services/Review/ReviewSession.php` main inner loop only:
  - read key without folding case (`$rawKey = $this->readKey()`), then derive `$key = strtolower($rawKey)` for the switch and `$decideOnce = in_array($rawKey, ['A','S','T'], true)` for the recorder gate.
  - In the `a`/`s`/`t` arms, call `recordAndCaptureRuleId()` only when `$decideOnce` is false; otherwise pass `null` for `createdRuleId`. `TransactionActions::approve|skip|markTransfer` always run.
  - Two-line prompt: `[a]pprove  [s]kip  [t]ransfer  [u]ndo  [d]etails  [q]uit` then `(uppercase = decide once, don't remember) >`.
  - Override sub-loop (`runOverrideLoop`), tail-prompt, show-details prompt, and rule-conflict prompt are unchanged — they keep `strtolower($this->readKey())` and have no uppercase semantics.
- `tests/Feature/Services/Review/ReviewSessionPayeeRulesTest.php` adds five tests (now 18 tests total in the class, all green): uppercase A/S/T happy paths confirming no `payee_rules` row is created; uppercase A over an existing rule confirming action/skip_reason/updated_at are unchanged; uppercase-then-undo confirming the row reverts and `popAndRevert()` does not attempt a rule delete.
- `docs/SPEC.md` §7.1 keystroke list extended with the uppercase A/S/T entry; the example prompt now shows the modifier hint line.

### Assumptions made

- The recorder dependency on `ReviewSession` remains nullable (`?PayeeRuleRecorder`); when no recorder is wired, lowercase and uppercase are observationally identical (both already skip the recorder call). No callers were updated.
- Skip-reason behaviour for `S` is identical to `s` — the prompt fires either way; the reason is persisted on the transaction even though no rule is recorded.
- `popAndRevert()` was already null-tolerant on `createdRuleId`, so undo of an uppercase decision is a no-op for the rules table by construction (no schema or behaviour change required there).
- The `runOverrideLoop`, tail-prompt, and rule-conflict prompts continue to fold case via `strtolower`, so uppercase keys typed there behave as before (ignored when not in the accepted set).

### Blast radius

- `ReviewSession` interactive main loop only. `PayeeRuleRecorder`, `PayeeRuleEngine`, `RecordResult`, `TransactionActions`, the `payee_rules` migration, and the `:rules:list` / `:rules:delete` commands are untouched.
- Existing `ReviewSessionTest`, `ReviewCommandTest`, and the GH #39 tests in `ReviewSessionPayeeRulesTest` continue to pass — none feed uppercase keys, so the new branch is dead code on every path they exercise.
- Output-string change: any external consumer asserting on the exact prompt text would break. The only assertion on review-prompt strings inside this repo lives in the override-loop tests, which use a different prompt and are unaffected.

### Open threads

- Caps-lock typo path is not solved — an operator with caps-lock on intends `s` and gets `S`, silently suppressing rule creation. Mitigation considered (confirmation prompt on uppercase) and rejected as too noisy; the prompt-line hint is the documented contract.
- Hint-line verbosity: one extra line per row. Tracking only via human comfort during real review sessions; if it proves noisy, a `--quiet` flag is a cheap follow-up.
- Undo of an uppercase decision is indistinguishable from undo when a guard already declined to create a rule — irrelevant for correctness today, but if rule audit trails are added later, the `decideOnce` flag would need to be persisted alongside the decision.

---

## Auto-decision rules: remember approve/skip/transfer per (bank, payee) (GH #39)

### What changed

- New table `payee_rules` (`database/migrations/2026_05_07_171806_create_payee_rules_table.php`): `id`, `bank_slug` FK to `banks`, `counterparty_name`, `action` (CHECK in approved/skipped/transfer), `skip_reason` text nullable (CHECK: non-null only when action='skipped'), unique on `(bank_slug, counterparty_name)`, standard timestampTz timestamps.
- New model `app/Models/PayeeRule.php` reusing `HasUuidV7`, casting `action` to `TransactionStatus`.
- New service `app/Services/Review/PayeeRuleRecorder.php` with `record()` (creates a rule on first interactive decision; `RecordResult::Created`/`AlreadyExists`/`SkippedByGuard`), `findFor()`, `update()`, `delete()`. Guards: resolution level ≥ 4, blank counterparty_name, name on bank-internal denylist or operator-name denylist (case-insensitive).
- New service `app/Services/Review/PayeeRuleEngine.php` with `applyRules(Collection $queue)`: bulk-loads matching rules (one query for all distinct `(bank_slug, counterparty_name)` keys in the queue), routes each match through `TransactionActions::approve|skip|markTransfer`. Returns `appliedIds` + `byAction` summary.
- New enum `app/Services/Review/RecordResult.php` (Created / AlreadyExists / SkippedByGuard).
- New artisan commands `spendula:rules:list [--bank=]` and `spendula:rules:delete <id>` under `app/Console/Commands/Spendula/`. Both lock-free.
- `config/spendula.php` adds `payee_rule_guards.bank_internal_payees` (built-in) and `payee_rule_guards.operator_names` (split from `SPENDULA_OPERATOR_NAMES`).
- `.env.example` documents `SPENDULA_OPERATOR_NAMES=`.
- `app/Console/Commands/Spendula/ReviewCommand.php` injects the engine + recorder, runs auto-apply over the `fetched` queue before the interactive session, and passes `appliedIds` / `byAction` into `ReviewSession::run()`.
- `app/Services/Review/ReviewSession.php` now (a) prints the auto-apply summary line and `Show details? [y/N]` when `autoAppliedIds` is non-empty, (b) runs an override sub-loop for those rows offering `[a][s][t][k][d][q]` plus a conflict prompt `[u]pdate / [d]elete / [k]eep` when the override action differs from the rule, (c) calls `PayeeRuleRecorder::record()` after each interactive `a`/`s`/`t` decision in the main loop. Constructor signature: `(Command, TransactionActions, ?Closure $keyReader = null, ?PayeeRuleRecorder $recorder = null)` — keyReader stays at position 3 so existing tests are unaffected.
- `docs/SPEC.md` §7.1.1 documents the auto-decision pipeline (auto-create / auto-apply / override) and the two new artisan commands.
- `DECISIONS.md` appends GH #39 entry: separate-table over JSON-on-transactions, hard-delete over `superseded` lifecycle, denylist over remittance-predicate disambiguation.
- New tests: `tests/Feature/Services/Review/PayeeRuleRecorderTest.php` (12 tests on guards + create/update/delete/findFor), `PayeeRuleEngineTest.php` (7 tests on bulk apply, isolation, case-sensitivity), `ReviewSessionPayeeRulesTest.php` (9 tests on summary, override, conflict prompt branches), `RulesListCommandTest.php` (4 tests), `RulesDeleteCommandTest.php` (2 tests). Existing `ReviewSessionTest` and `ReviewCommandTest` unchanged and still green.

### Assumptions made

- Mock ASPSP behaviour assumed identical to production EB: post-`name_rules` `counterparty_name` is canonical and stable across syncs.
- Match key is exact `(bank_slug, counterparty_name)` equality, case-sensitive — relies on the L0/L1 `name_rules` pipeline (#33) to canonicalise names beforehand. `Bolt.eu` and `BOLT.EU` would NOT auto-apply across each other.
- `bulkApproveTrivial` rows do NOT generate rules (they bypass `ReviewSession::run()`).
- Auto-applied rows are skipped from the main interactive loop because they are no longer at status `fetched`.
- Postgres session timezone was UTC during the test run; CHECK constraints fire on insert (not on read).
- The advisory lock `REVIEW` continues to be the only lock the review pipeline acquires; the engine and recorder are invoked under it via `ReviewCommand`.

### Blast radius

- **`ReviewCommand`**: now has 3 injected services instead of 1. Existing `--bulk-approve-trivial` flag still works the same.
- **`ReviewSession::run()`**: signature changed from `run(): array` to `run(array $autoAppliedIds = [], array $autoByAction = [...]): array`. Defaults preserve the existing call surface so external callers (none in repo) and tests calling `$session->run()` with no args still work.
- **`ReviewSession::__construct`**: added optional 4th parameter `?PayeeRuleRecorder`. Existing 3rd-arg `Closure` keyReader call sites are unaffected.
- **`TransactionActions`**: untouched. Both engine (auto-apply path) and override sub-loop call through it for state mutations, so undo / push semantics are preserved.
- **Rule lifecycle**: a stale rule (operator changed mind) is fixable via `spendula:rules:delete <id>` or via the override path. There is no automatic rule-staleness detection.

### Open threads

- **Intra-session auto-apply**: if the operator interactively decides "approve Spotify" and the queue has 4 more pending Spotify rows, those 4 rows are still decided manually because auto-apply ran once at session start. Future improvement: after each interactive decision creates a rule, scan the remainder of the queue for matches and apply in-place. Adds undo-stack complexity; deferred.
- **ATM-vs-self-transfer ambiguity**: still mitigated only by adding the operator's name(s) to `SPENDULA_OPERATOR_NAMES`. The proper fix (rule conditional on a remittance predicate) remains a separate follow-up.
- **Rule normalization**: case-insensitive matching is one config flag away. Decision today: keep it strict; relax only if drift becomes a real problem.
- **Bank-account scoping**: rules are per-`(bank_slug, counterparty_name)` not per-`(bank_account_id, counterparty_name)`. If the operator has two accounts under the same bank slug (e.g. "ING-RO Personal" and "ING-RO Business" sharing slug `ing-ro`) and the same payee should auto-apply differently per account, the current schema can't express that. Add a nullable `bank_account_id` column if a real need appears.

---

## Counterparty cleanup at L0/L1 via `name_rules` (GH #33)

### What changed

- `app/Services/Counterparty/Rules/RuleLoader.php` — new `nameRulesForBank(string $bankSlug): list<Rule>` parallel to `forBank()`, with its own per-slug cache. The private `loadFile()` is now parameterised by the top-level array key (`'rules'` | `'name_rules'`); a missing `name_rules` key is *not* an error (returns `[]`), while a missing `rules` key remains a validation failure. `clearCache()` resets both caches.
- `app/Services/Counterparty/Resolver.php` — at L0 and L1, the resolved candidate name is passed through the bank's `name_rules` before being returned. Empty post-rule result falls through to the next ladder step (same fall-through guarantee `RuleEngine::apply` already gives at L2). When `bankSlug` is `null`, no rules are consulted — current behaviour preserved. The level on the rewrite is the originating level (still 0 or 1) — cleanup, not transition. Names returned at L0/L1 are now also truncated to 64 chars via `mb_substr`, matching the L2/L3 contract (the truncation was implicit before because EB structured names rarely exceed 64; the explicit `mb_substr` makes the contract uniform).
- `config/counterparty-rules-available/revolut.json` — new `name_rules` block with one rule, `bolt-eu-embedded-id`, collapsing all four Bolt variants from the issue (`Bolt.euo<digits>`, `Bolt.eu/o/<digits>`) under the single canonical payee `Bolt.eu`. Top-level file `description` updated to explain the two-list convention.
- `tests/Unit/Services/Counterparty/ResolverTest.php` — five new test cases: L0 with name rule that matches; L0 with name rule that doesn't match (clean string passes through); L0 with `bankSlug=null` (no rules consulted); L1 inverted with name rule that matches; slashed Bolt variant.
- `tests/Unit/Services/Counterparty/Rules/RuleLoaderTest.php` — six new test cases covering the `name_rules` schema: returns `[]` when only `rules` present; returns `[]` when no file; loads alongside `rules`; invalid regex throws; missing required field throws; empty `tests` throws; cache stability per bank.
- `docs/SPEC.md` §6.8 — Level 0 and Level 1 entries now mention the `name_rules` cleanup pipeline and that the level is unaffected.
- `DECISIONS.md` (new) — records the option-2 (separate `name_rules` array) decision, the rejected alternatives (unified list, hard-coded patterns, `target` discriminator), the constraints, and the consequences (notably: deferred ATM case).

### Assumptions made

- The `RuleEngine::apply()` semantics unchanged: same engine processes `name_rules` and `rules`, since the two lists share the `Rule` schema. No `RuleEngine` changes were made.
- `RuleFixtureSelfTest` continues to validate only `rules`, not `name_rules`. The new Bolt fixtures are validated indirectly via the `ResolverTest` cases that resolve against the available/ directory directly. (A follow-up could extend `RuleFixtureSelfTest` to also walk `name_rules` per file; deferred — fixtures are validated by `RuleLoader` schema validation on load already.)
- `counterparty_resolution_level` continues to mean "ladder level the data came from", not "ladder level after cleanup". The recompute output (`name_changed=4 level_changed=0` for the Revolut Bolt collapse) is the intended shape.
- Postgres session timezone was UTC during the live demo (per `config/database.php`). Dev DB is `spendula_dev` per CLAUDE.md.
- The recompute command does not touch `dedup_hash` (per its docblock) — name-rule rewrites change `counterparty_name` but not the underlying dedup invariant. Existing `(bank_account_id, dedup_hash, occurrence)` rows remain stable.
- Mock ASPSP behaviour was not exercised in this change. The L1 path is exercised against Revolut `creditor.name` data with synthetic `CRDT` direction in unit tests.

### Blast radius

- **Resolver call sites** (2): `MatchUpdateOrInsert::insert()` (sync) and `CounterpartyRecomputeCommand::handle()` (manual). Both pass a non-null bank slug, so both now consult `name_rules`. Banks without a `name_rules` array (BCP, ING-RO business/personal) get `[]` from the loader and the L0/L1 result is `trim()`-passed through — verified empirically with `name_changed=0 level_changed=0` on all three.
- **CounterpartyRulesAddCommand** intentionally duplicates resolver L0/L1/L3/L4 logic for its preview output. It does *not* yet apply `name_rules` to its L0/L1 preview. Out of scope; the operator workflow is to hand-edit `name_rules` for now (issue plan called this out as a follow-up).
- **`dedup_hash`** is unaffected: `Resolver::normalize()` still operates on the *raw* pre-resolution counterparty (creditor/debtor name pulled by `MatchUpdateOrInsert::extractRawCounterparty`), not on the post-cleanup `counterparty_name`. The dedup invariant from SPEC §6.3 is preserved.
- **YNAB push memo / payee_name** reads `counterparty_name`, so on the next push, Revolut Bolt rides will land in YNAB under a single payee `Bolt.eu`. This is the point of the change.
- **Existing tests** all green (309 PHPUnit, PHPStan level 8 clean, Pint clean).

### Open threads

- **ATM withdrawal collapse — deferred to a follow-up issue.** The issue lists three Revolut ATM rows that should collapse away from the holder's name. Cannot be expressed in `name_rules` as designed: a name-only rule keyed on `JANE DOE` would fire on legit self-transfers between the operator's own accounts. Solving it cleanly needs a rule predicate that inspects both name and remittance (e.g. *if remittance =~ /^Cash at/i, rewrite name to "ATM withdrawal"*), which is a schema extension worth its own DECISIONS entry.
- **`spendula:counterparty:rules:add` does not author `name_rules`.** Existing `--target=name|remittance` flag does not exist. Hand-edit JSON for now. If `name_rules` proliferates beyond Revolut, add the flag.
- ~~`RuleFixtureSelfTest` does not walk `name_rules` fixtures.~~ Wired in round 2 (codex review): `RuleLoader::availableNameRules()` added; `RuleFixtureSelfTest` and `spendula:counterparty:rules:test` both iterate `name_rules` alongside `rules`. Output keys are now `[bank/rules/<name>]` and `[bank/name_rules/<name>]`. The `--bank=revolut` CLI now reports 17 fixtures (13 + 4) instead of 13.
- **Loader cache layering** is two parallel per-slug caches. Acceptable for v1; collapse into a single `forBankBundle()` if profiling ever shows it.
- **`mb_substr` truncation at L0/L1** applies *only* when a `name_rules` rewrite fires. Banks with no `name_rules` (and the null-bank-slug path) return the raw L0/L1 name verbatim — no trim, no truncation — preserving pre-PR behaviour exactly. Round-2 codex review caught a regression where the empty-rules path was unconditionally trimming; fixed by short-circuiting before the engine call.

---

## ING Romania L2 — collapse digit-bearing names: Spotify, Twilio, BUGETUL DE STAT, Lazada, 2C2P (GH #36)

### What changed

- `config/counterparty-rules-available/ing-ro-business.json` (auto-shared
  with `ing-ro-personal` via existing symlink) — three new rules and one
  sharpened rule:
  - `card-spotify` (new): `Spotify P<hex>  SE  Stockholm` → `Spotify`.
    Drops the per-month booking ref *and* the location.
  - `card-twilio` (sharpened): tighter regex requires the `US <support-phone>`
    tail; emits `Twilio` (previously kept `US 844-8144627`). Existing test
    fixture's expected output updated.
  - `card-2c2p-lazada` (new): `WWW.2C2P.COM*LAZADA PAY  TH  BANGKOK` →
    `Lazada`.
  - `card-2c2p-truncated` (new): `2C2P   *<TAG>  TH  BANGKOK` →
    `2C2P <TAG>` (e.g. `2C2P BAN`). Keeps the processor qualifier because
    the merchant tag is opaquely truncated.
  - `transfer-bugetul-de-stat` (new): `Beneficiary, BUGETUL DE STAT/<digits>,
    To account, ...` → `BUGETUL DE STAT`. Inserted *before* `beneficiary-first`
    so the more specific shape wins.
- `beneficiary-first` (existing) — description updated; the
  `BUGETUL DE STAT/12345678` test fixture relocated to the new rule and
  replaced with a synthetic `BUGETUL DE STAT` (no /digits) shadow check
  so the engine's fallthrough is exercised.
- `structured-card-purchase` (existing) — description updated; two shadow
  fixtures added (`Spotify Premium  US  San Francisco`,
  `WWW.TWILIO.COM  IE  DUBLIN`) confirming near-miss card shapes
  fall through intact.
- No PHP code changes. No migrations. No new commands.

Live recompute against `spendula_dev`: 11 rows renamed, 5 canonical payees
remaining (`Spotify`, `Twilio`, `BUGETUL DE STAT`, `Lazada`, `2C2P BAN`).
Resolution-level distribution unchanged.

### Assumptions made

- **Card-row envelope shape is stable.** Every regex pins the
  `Card number, **** XXXX, Transaction at, MERCHANT(, Authorization date, ...)?`
  envelope produced by ING Romania today. If ING ever drops the
  `Authorization date` tail or reorders fields, these rules silently fall
  through to the catch-all (which is the safe failure mode, not a defect).
- **Spotify always books from `SE Stockholm`.** Live data shows three
  consecutive months on this locale; no other Spotify shape observed. A
  US/EU-locale Spotify row would fall through to `structured-card-purchase`,
  not into `card-spotify`. Acceptable.
- **Twilio's tail `US <ddd-ddddddd>` is constant.** The current support
  phone `844-8144627` matches `\d{3}-\d{7}`. If Twilio rotates phones,
  the digit shape still holds; if they ever route through a non-US locale,
  it falls to `structured-card-purchase`.
- **2C2P's `BAN` is genuinely opaque.** We collapse the row to
  `2C2P BAN` rather than guessing the original merchant. If real-world
  data later reveals what `BAN` truncates from, sharpen then.
- **`transfer-bugetul-de-stat` is digit-strict** — only matches
  `BUGETUL DE STAT/<one-or-more-digits>`. A bare `BUGETUL DE STAT`
  (no slash) falls to `beneficiary-first`. Verified by the synthetic
  shadow fixture.
- **Mock ASPSP** is not exercised; this is pure ING Romania live-data
  cleanup.
- **Postgres session timezone** is irrelevant; the change is data-only.
- **YNAB-pushed rows are untouched.** `spendula:counterparty:recompute`
  only writes to fetched/approved/skipped rows (per the issue's
  out-of-scope clause).

### Blast radius

- Affects `ing-ro-business` and `ing-ro-personal` (shared file via
  symlink). No other bank slug touches these rules.
- The first-match-wins ordering of the rule array is now load-bearing
  for one new pair: `transfer-bugetul-de-stat` must remain before
  `beneficiary-first`. If a future edit re-sorts the array
  alphabetically or otherwise, BUGETUL DE STAT rows would silently
  start matching `beneficiary-first` again. The description on each
  rule documents the dependency.
- Twilio's prior output was `Twilio US 844-8144627`; it is now
  `Twilio`. Any YNAB rows already pushed retain the old payee
  (recompute does not rewrite them); future rows aggregate under the
  new canonical name. Operator may want to manually rename in YNAB
  if cross-period aggregation matters.
- `RuleFixtureSelfTest` auto-discovers JSON fixtures, so the 6 new
  positive + 2 shadow fixtures all run on every test invocation.
- 297 tests pass. PHPStan level 8 clean. Pint clean.

### Open threads

- L0/L1 noise (Revolut `Bolt.eu/o/...` etc.) is explicitly out of scope;
  deferred until concrete demand and a separate L0/L1 post-hook layer.
- `2C2P BAN` may collapse further once the real merchant surfaces.
- A future Spotify row from a non-`SE Stockholm` locale will not be
  caught by `card-spotify`; revisit if/when observed.

---

## Counterparty resolver — L3 falls back to `bank_transaction_code.description` (GH #31)

### What changed

- `app/Services/Counterparty/Resolver.php` — L3 now tries
  `bank_transaction_code.description` (trimmed, truncated to 64
  chars) when `additional_information` is missing or empty. Order
  is `additional_information` first, `bank_transaction_code.description`
  second; both produce `level=3`. L4 `(Unknown)` is unchanged for
  rows with neither populated.
- `app/Console/Commands/Spendula/CounterpartyRulesAddCommand.php` —
  the private `resolveLikeResolver()` ladder duplicate (used by the
  rules-add impact preview) gets the same fallback so the preview
  matches the live resolver.
- `tests/Unit/Services/Counterparty/ResolverTest.php` — five new
  unit tests cover the BTC-description fallback, the
  `additional_information`-wins priority, the 64-char truncation,
  the whitespace-only `additional_information` skip, and the L4
  fall-through when `bank_transaction_code` is missing, non-array,
  or has a non-string/blank `description`.
- `docs/SPEC.md` §6.8 — Level 3 bullet now describes the fallback
  chain explicitly.

### Assumptions made

- **`bank_transaction_code.description` is bank-provided
  human-readable text.** ING Romania populates it with English
  strings like `Service Fee` and `Interest adjustment`. Other banks
  may send a code or a localised string. We accept that: anything
  non-empty there is strictly more informative than `(Unknown)`. If
  a bank turns out to send opaque codes, the right fix is a
  per-bank L3 rule, not gating this change.
- **Truncation at 64 chars** matches the rest of the ladder.
- **Mock ASPSP behaviour** was not exercised in this change — the
  fallback only fires when L0/L1/L2 all miss and
  `additional_information` is empty, which Mock ASPSP rows do not
  produce. Live ING Romania data is the load-bearing case.
- **Postgres session timezone** is irrelevant here; this is a pure
  in-memory derivation against `transactions.raw_payload` shape.

### Blast radius

- **`spendula:sync`** — every new `transactions` row is resolved
  through this ladder. Rows whose ladder previously terminated at
  L4 with a populated `bank_transaction_code.description` will now
  be persisted with `counterparty_name` set to that description and
  `counterparty_resolution_level = 3`.
- **`spendula:counterparty:recompute`** — re-runs the ladder over
  `fetched / approved / skipped` rows. Existing L4 rows with a
  populated `bank_transaction_code.description` will move to L3.
  `dedup_hash` is intentionally not updated by recompute, so this
  is non-destructive on the dedup contract.
- **`spendula:review`** — formerly L4 `(Unknown)` rows now show
  with a meaningful payee name and lose the L4 warning icon.
- **`spendula:push`** — already-pushed rows are not retro-updated.
  YNAB sees the new payee text only on rows pushed after the
  recompute.
- **`spendula:counterparty:rules:add`** preview output stays
  consistent with the live resolver because the duplicate ladder
  was updated in lockstep.

### Open threads

- Operator should run `php artisan spendula:counterparty:recompute
  --dry-run` then `--apply` to lift the four ING RO L4 rows
  (`Service Fee` ×3, `Interest adjustment` ×1) up to L3.
- No follow-up planned for opaque-code banks until one is observed
  in production data.

---

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
  a 337-row real-data dataset) reports
  `name_changed=0` — the rule engine reproduces the prior
  code-based resolver output exactly (including the
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
    than being mis-cut. EXAMPLEGYM (4-digit-ref merchant shape
    observed on BCP) is accepted as collateral — its rows fall
    through to the noisy form, which is strictly safer than
    over-merging distinct year-suffixed merchants.
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
  unit tests. Real-data fixtures captured from BCP
  transactions: GIN CLUBE PORT, NOS Comunicaco, OCIDENTAL/MEDIS
  with DI-prefixed identifier (plus an accented MÉDIS variant),
  EDP COMERCIAL with hyphen artifact, the PAG BXVAL Via Verde
  line, and both LEV ATM branches (with-location and bare-
  fallback) including a multi-word location with internal doubled
  spaces. Regression fixtures cover the false-positive shapes
  surfaced across six codex review rounds: DD without creditor-id
  suffix, DD with embedded year/plan codes, DD with numeric
  intermediate token, DD with short reference directly before the
  creditor id, and the EXAMPLEGYM trade-off documenting the
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

---

## GH #6 — `spendula:pending --json` for agent-driven review

### What changed

- **NEW** `app/Console/Commands/Spendula/PendingCommand.php` — read-only artisan command `spendula:pending`. Supports `--json` (single JSON document on stdout), `--bank=<slug>` (filter by bank), and `--limit=N` (cap rows). No advisory lock acquired; zero DB writes. JSON output uses `OutputInterface::OUTPUT_RAW` to prevent Symfony's formatter from stripping `<tag>`-style strings in field values.
- **NEW** `tests/Feature/Commands/Spendula/PendingCommandTest.php` — 10 feature tests covering: fetched-only filter, JSON schema, empty queue (both branches), bank filter, limit cap, invalid limit, read-only invariant, debit sign semantics, and formatter-tag preservation.
- **EDIT** `app/Services/Review/ReviewSession.php` — appended `->orderBy('id')` tie-breaker to the `fetched` queue query so `PendingCommand` and `ReviewSession::run()` stay in lock-step row ordering for deterministic `--limit` slicing.
- **EDIT** `docs/SPEC.md` — added §7.1.2 describing `spendula:pending` with the full JSON shape table, amount-sign semantics, ordering contract, and concurrency note.

### Assumptions made

- `bankAccount.display_name` is operator-friendly enough for `bank_account_label` with no localisation needed.
- JSON shape (13 keys) is stable enough to commit as the v1 agent contract; no `schema_version` field added (YAGNI per CLAUDE.md, risk noted in plan).
- Agent tolerates point-in-time snapshots and re-runs if rows disappear between probe and decide.
- No Mock ASPSP or YNAB API was exercised — command is purely read-only against local DB.
- Postgres session timezone was UTC during test runs (standard per `config/database.php`).

### Blast radius

- Pure additive: no existing command or service modified beyond the one-line `->orderBy('id')` addition in `ReviewSession::run()`. That change has no functional effect when row ordering was already unique on `(bank_account_id, booking_date, occurrence)`; it only matters when those three columns produce a tie.
- The `Transaction::query()->where('status', 'fetched')` shape is now shared between `ReviewSession` and `PendingCommand`. If the ordering convention changes in one, the other should track it.

### Open threads

- Future `--status=` filter (currently fixed to `fetched` per acceptance criteria; out of scope).
- Pagination cursor for very large queues (out of scope for v1).
- `schema_version` field in JSON document if cross-repo coordination grows beyond one consumer (flagged in plan risks, deferred).

---

## `spendula:decide <id> <action>` — single-shot review decision (GH #7)

### What changed

- `app/Console/Commands/Spendula/DecideCommand.php` (new) — non-interactive sibling to `spendula:review`. Accepts a transaction UUID, an action (`approve|skip|transfer`), an optional `--reason` (skip-only), and an optional `--remember` flag. Acquires `AdvisoryLock::REVIEW`, validates the action and status guard, delegates to the existing `TransactionActions` service, and optionally calls `PayeeRuleRecorder::record()`. Prints `Decided <uuid>: <action> (rule recorded: yes|no)` on success. Exits non-zero (with descriptive stderr) on bad action, bad action+reason combination, missing transaction, non-`fetched` status, or lock contention.
- `tests/Feature/Commands/Spendula/DecideCommandTest.php` (new) — 12 test cases covering: approve/skip/transfer with and without `--remember`; reason-on-non-skip rejection; unknown action; already-decided row; missing UUID; lock-busy via a second PDO connection; guarded-payee (`ATM`) denylist suppressing rule creation while still applying the decision.

### Assumptions made

- `AdvisoryLock::REVIEW` is the correct lock — the command mutates the same status columns as the interactive `spendula:review` session and must contend with it.
- `TransactionActions` and `PayeeRuleRecorder` are stable contracts (unchanged since GH #39); no new mutation logic was needed.
- Mock ASPSP behaviour not relevant here — the command is lock/state-machine logic only; no Enable Banking calls.
- YNAB not called — `spendula:push` remains the only path to YNAB.
- Postgres session timezone was UTC during the test run (config baseline).

### Blast radius

- Additive only: one new artisan command + tests. No service edits, no migration, no schema changes.
- `DecideCommand` contends with `ReviewCommand` for the `REVIEW` advisory lock. That is the correct contract; no data-corruption risk.

### Open threads

- No undo: agent-driven decisions are corrected via a follow-up `spendula:rules:delete` and/or manual SQL revert (same as documented for the interactive flow).
- Lock starvation in a tight agent loop is accepted: the agent and the operator should not run simultaneously (documented in the issue risks).
