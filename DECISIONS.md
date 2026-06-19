# Decisions

Architectural choices, alternatives considered, and the constraints that drove them. Append-only: don't rewrite history when reversing a decision — add a new entry that supersedes it.

---

## 2026-05-07 — Counterparty cleanup at L0/L1: separate `name_rules` list (GH #33)

**Decision.** The per-bank counterparty rule JSON gains an optional `name_rules: []` array, parallel to the existing `rules: []`. Entries share the same `Rule` schema (regex pattern + replacement + post hooks + fixtures) and the same first-match-wins / empty-result-falls-through semantics. The `Resolver` runs `name_rules` against the resolved L0 candidate and the L1 candidate before returning; on match the rewritten string is returned at the same level (still 0 or 1) — the rewrite is cleanup, not a level transition. `rules` continues to operate only at L2 against `remittance_information[0]`.

**Alternatives considered.**

1. *Run the existing `rules` list at L0/L1 too.* Cleanest mental model: every level passes through the same cleanup pipeline. Rejected because most existing rules are anchored to remittance shapes (`COMPRA NNNN`, `Refund from`, `www.kiwi.com*`) and feeding a structured creditor name into them would either misfire silently or just no-op — but auditing every shipped rule for safety under structured-name inputs was load-bearing work this issue didn't budget. The two-list split keeps the L2-on-remittance contract intact and the regression risk localised to the new code path.
2. *Hard-code Revolut Bolt + ATM patterns inside `Resolver`.* Rejected: would pollute the resolver with bank-specific knowledge that already lives, by design, in JSON. The existing rule engine is the right home.
3. *Single unified list with an explicit `target: "name" | "remittance"` field per rule.* Tempting for orthogonality but adds a dispatch field that exists only for one bank in v1, and makes the loader's per-call-site filtering noisier than the parallel-list approach. Revisit if `name_rules` ships across more than two banks and either list outgrows ~20 entries.

**Constraints that drove the choice.**

- Revolut writes the dirty merchant string directly into EB's structured `creditor.name` (e.g. `Bolt.euo1234567890`), so the rule engine has to be reachable from L0/L1 — there is no remittance to extract from.
- The existing `rules` list semantics and tests are a recently shipped contract (PR #34, ING-RO PRs #32/#37). Layering a second list keeps that contract untouched.
- The `Rule` type is a final readonly value class; adding a `target` discriminator would break its single-purpose shape.

**Consequences.**

- **Easier:** name-side cleanup for any bank, by adding a `name_rules` array to its JSON. No code changes required to onboard another bank.
- **Easier:** the L2-only `rules:add` and `rules:test` artisan commands keep working unchanged — they touch only the `rules` array.
- **Harder:** rules that need to inspect *both* a name and a remittance simultaneously (e.g. rewrite the holder-name to `ATM withdrawal` only when the remittance starts with `Cash at`) cannot be expressed in `name_rules` as designed. That's the deferred ATM case from the issue. Solving it cleanly needs a schema extension (rule predicate over a second field) and is its own follow-up issue.
- **Slightly worse:** the `RuleLoader` now caches two lists per bank slug (the `forBank` cache for `rules` and the `nameCache` for `name_rules`). Two cache misses on first access for a bank that consults both. Acceptable for v1; collapse into one bundled load if it ever shows up in profiling.


---

## 2026-05-07 — Auto-decision rules: separate `payee_rules` table, hard-delete, denylist instead of disambiguation (GH #39)

**Decision.** Auto-apply per-`(bank_slug, counterparty_name)` review verdicts via a new `payee_rules` table. The rule action reuses `TransactionStatus` values (`approved`, `skipped`, `transfer`). Lifecycle is hard-delete via `spendula:rules:delete`. Names that legitimately resolve to multiple verdicts (the operator's own legal name, bank-internal generics like `REVOLUT`) are kept *out* of the rule table by a config-driven denylist; the auto-decision pipeline silently declines to record a rule when a guard trips.

**Alternatives considered.**

1. *Bake the auto-apply onto `transactions.counterparty_name` itself, e.g. as a JSON column or a `transactions.auto_action` derived during sync.* Rejected: the verdict is operator metadata, not bank metadata. Conflating them couples sync to review state.
2. *Use the existing `name_rules` engine to also encode "auto-approve" / "auto-skip" actions.* Rejected: `name_rules` is a name-cleanup pipeline (string-to-string). Adding actions would overload its semantics, and rule files are checked into `config/` — they're conventions, not per-operator decisions.
3. *Soft-delete via `superseded_by_id`, mirroring `bank_connections`.* Rejected: rules are operator-managed metadata, not a regulated audit trail. PSD2 does not require rule history; the operator already has git history of `config/` changes if they care, and `payee_rules` rows aren't config. Hard-delete keeps the data model trivial; revisit if a real audit need appears.
4. *Solve the ATM-vs-self-transfer ambiguity by extending the rule schema with a remittance predicate (rule conditional on a second field).* Deferred to its own issue. The denylist short-circuits the ambiguous-by-name case at the cost of forcing the operator to decide each transaction manually for those payees — fine for v1.

**Constraints.**

- The push pipeline (§7.2) and the L0–L4 counterparty resolver (§6.8) must not change behaviour. Auto-decision only mutates rows transitioning `fetched` → `approved`/`skipped`/`transfer`, exactly as a manual decision would.
- The match key must be deterministic. Once `name_rules` (#33) canonicalised L0/L1 names, exact `(bank_slug, counterparty_name)` equality is enough; fuzzy matching would couple the engine to a similarity threshold that can't be revisited without re-evaluating every existing rule.
- The first-decision write must not silently overwrite an existing rule. Operator decisions are authoritative, but the *first* one for a payee is special — every subsequent decision for that pair runs through auto-apply, not record. The override path (§7.1.1) is the only place an existing rule can change.

**Consequences.**

- **Easier:** the operator decides each payee once and never again.
- **Easier:** rule storage is independent of `transactions.raw_payload` — recomputing counterparty resolution doesn't invalidate any rule.
- **Easier:** auditing and pruning live in `spendula:rules:list` / `:delete` without an HTTP surface.
- **Harder:** two payees that *should* share a rule but render with different `counterparty_name` values (case drift, post-rule cleanup) won't auto-apply across that boundary. Fix by tightening the upstream `name_rules` rather than relaxing this engine.
- **Harder:** the denylist sidesteps the ATM/self-transfer ambiguity; the operator still re-decides every transaction for those payees. The proper fix (rule predicate over remittance) is queued as a separate follow-up.


---

## 2026-05-08 — ATM cash withdrawal short-circuit in the resolver (GH #42)

**Decision.** `Resolver::resolve()` gains a structural short-circuit at the top: when `credit_debit_indicator = "DBIT"` AND `bank_transaction_code.code = "ATM"` (case-insensitive), return a configurable synthetic label (`config('spendula.resolver.atm_cash_label')`, default `"ATM Cash"`, env override `SPENDULA_ATM_CASH_LABEL`) at **level 1**. The label is wired into `Resolver` via constructor injection (matching the `EnableBankingClient` pattern) and bound as a singleton in `AppServiceProvider`. CRDT, non-ATM transaction codes, and missing/non-string codes all fall through to the existing L0/L1/L2/L3/L4 ladder unchanged.

**Alternatives considered.**

1. *Promote `bank_transaction_code` to a first-class match field in the rule schema* (the issue's option b). The "proper general fix" called out in the GH #33 entry above. Rejected for this issue because it requires a Rule-schema extension (`when` / structural-match predicate), loader/validator/fixture-runner support, plus a one-shot shape decision (path strings vs nested object) — all of which is bigger than the issue's scope and forces a design call that's hard to revisit. **Not blocked by this PR**: a future case that genuinely needs name + remittance simultaneous predicates can still ship the schema extension; the operator runs `spendula:counterparty:recompute` and the new rule supersedes the resolver branch.
2. *Hard-code the label in PHP without a config knob.* Rejected: matches the GH #33 alternative-2 rejection in spirit (operator-facing strings should be reachable without a code change, especially for non-English deployments).
3. *Per-bank `atm_cash_label` keyed by `bank_slug`.* Deferred: no second bank has shown divergent ATM semantics yet, and the config can be widened from `string` to `string|array<string,string>` later without a migration. Backwards-compatible.
4. *Add a new resolution level (e.g. L0.5 or L5) for "structurally-derived" payees.* Rejected: changes the operator-facing review display, breaks the `counterparty_resolution_level >= 4` guard in the GH #39 `PayeeRuleRecorder`, and adds a level number that nothing else uses. L1 is acceptable — structurally-derived counterparty is closer in confidence to L1 (direction-inverted name match) than to L2.
5. *Extract the ATM location from `Cash at <street>` remittance into the synthetic payee.* Deferred per the issue. The single stable label is the v1 contract; if location-aware aggregation later becomes desirable the regex (`^Cash at (.+)$`) is a small follow-up.

**Constraints that drove the choice.**

- The trigger field is ISO 20022 `bank_transaction_code` — universal, not bank-specific text. A built-in resolver branch keyed on a standard ISO field is not "polluting the resolver with bank-specific knowledge" in the sense the GH #33 entry rejected (that was about Bolt-style text patterns).
- The desired output is a single fixed string. Using the regex+replacement rule engine for a fixed-string output would be overkill.
- Rule-schema extension is a larger design conversation (see alternative 1) that this issue should not force.
- The resolver had no prior `config()` call — adding one in a service that's also constructed manually in unit tests would couple unit tests to Laravel bootstrap. Constructor injection avoids that and matches the existing pattern for `EnableBankingClient`'s base URL.

**Consequences.**

- **Easier:** every ATM withdrawal aggregates under one stable YNAB payee (`"ATM Cash"`), and the operator can opt to auto-skip them via the GH #39 pipeline (the synthetic label is a stable `(bank_slug, counterparty_name)` key — no operator-name denylist needed for the ATM case anymore).
- **Easier:** backfill is the existing `spendula:counterparty:recompute` artisan command. No migration, no special data path.
- **Easier:** `bulkApproveTrivial` (`counterparty_resolution_level <= 1` AND base currency) now also auto-approves ATM withdrawals consistently.
- **Harder:** future structural-field overrides (`FEES`, `INT`, etc.) will either accumulate as universal resolver branches or eventually motivate the schema extension. If the count of universal branches grows beyond ~3–4, that's the signal to revisit alternative 1.
- **Harder:** the GH #39 `operator_names` denylist is still useful for non-ATM self-transfer cases (the operator's own name appearing as a counterparty for an in-bank transfer), but the *primary* motivating case (ATM withdrawal) is now structurally classified. The denylist is no longer load-bearing for ATM correctness.
- **Slightly worse:** `Resolver` is now wired by `AppServiceProvider` rather than being container-auto-resolvable from its bare constructor. Tests can still construct it directly with an explicit label argument, so the test surface is unchanged.


---

## 2026-05-25 — Out-of-band rule install: `recordDirect()` as a second write entry point (GH #8)

**Decision.** A second entry point `PayeeRuleRecorder::recordDirect()` handles
explicit `(bank_slug, counterparty_name, action)` installs without a transaction
in scope. It shares the same denylist guard as `record()` but omits the
`counterparty_resolution_level` guard (no transaction to resolve; operator/agent
is trusted to supply a canonical name). Opt-in overwrite via `$force = true` calls
the existing `update()` helper; insert path mirrors `record()`'s `create()` shape.
A new `RecordResult::Updated` variant distinguishes force-overwrite from fresh
insert; `record()` never returns `Updated`. `isOnDenylist()` promoted from
`private` to `public` so callers can probe without attempting an insert.

**Alternatives considered.**

1. *Collapse `record()` and `recordDirect()` into one method with flags.* Rejected:
   the two methods have fundamentally different input shapes (a `Transaction` vs.
   bare `(bank_slug, counterparty_name)`) and different guard sets (resolution-level
   guard only makes sense when a transaction is in scope). Merging them obscures the
   contract and creates a method that does different things depending on which
   arguments are null.
2. *Write directly from the command via `PayeeRule::query()->create/update`.*
   Rejected: scatters the denylist guard across the command; a future caller
   (hypothetical web UI) could then bypass it by forgetting to inline the check.
   Keeping all `payee_rules` writes funnelled through the recorder is the same
   reason GH #39 split `Recorder` from `Engine`.

**Constraints.**

- `record()` must remain unchanged — it is the "first-time insert or do nothing"
  funnel called interactively from `ReviewSession`. Its resolution-level guard is
  load-bearing for automatic rule suppression at uncertain counterparty levels.
- The denylist guard in `recordDirect()` must be the same source of truth as in
  `record()`. Shared via `isOnDenylist()` — no duplication.
- No advisory lock: `payee_rules` is operator metadata, not transaction-mutating
  state. Matches `rules:list` / `rules:delete` — REVIEW lock is held by
  `ReviewCommand`, not by standalone rule-management commands.

**Consequences.**

- **Easier:** agent/operator can install or overwrite a rule without going through
  the interactive review session.
- **Easier:** the denylist guard is the centralised safety net for both entry
  points; any future caller cannot bypass it by going through `recordDirect()`.
- **Harder:** two write paths must be kept in sync if the denylist signature ever
  changes. Mitigated by the shared `isOnDenylist()` method and a test suite that
  exercises both.
- **Harder:** `RecordResult` now has four cases. Callers doing exhaustive `match`
  without a default arm will break at compile time — surfaced by PHPStan level 8.
  Audited before shipping: `ReviewSession` and `PayeeRuleRecorderTest` both consume
  `RecordResult` but neither uses exhaustive match, so adding `Updated` is safe.


---

## 2026-06-18 — Own-account transfer/FX classifier as post-resolution override (GH #14)

**Decision.** Add `OwnAccountClassifier` as a DB-aware post-resolution step applied
after `Resolver::resolve()` at the two call sites that turn `raw_payload` into
`counterparty_name`: `MatchUpdateOrInsert::parseIncoming()` (sync) and
`CounterpartyRecomputeCommand::handle()` (backfill). The `Resolver` is contractually
pure / no-DB, so the own-account IBAN lookup cannot live there. The classifier is a
separate service that reads `bank_accounts` once per instance (per sync run) into a
normalized-IBAN map.

Classification rules:
- Same-currency own-account → `counterparty_name = "<prefix> : <dest display_name>"`,
  `status = transfer` on insert (after cutoff/tracking guards). Prefix configurable via
  `SPENDULA_OWN_ACCOUNT_TRANSFER_PREFIX` (default "Transfer").
- Different-currency own-account (FX move) → `counterparty_name = fx_payee`
  (config `SPENDULA_OWN_ACCOUNT_FX_PAYEE`, default "Currency Exchange"), `status`
  unchanged (follows existing cutoff/tracking/fetched guards).
- External or ambiguous → `classify()` returns null, resolver output is preserved.

Duplicate-IBAN guard: `bank_accounts.iban` is nullable and NOT unique. When multiple
active accounts share the same normalized IBAN the result is ambiguous — `classify()`
returns null rather than silently picking one.

Direction-aware IBAN extraction: DBIT → `creditor_account.iban` first then free-text
"To account,"; CRDT → `debtor_account.iban` first then free-text "From account,".

Backfill (`spendula:counterparty:recompute`): promotes `fetched → transfer` only for
same-currency own-account rows. All other statuses (approved, skipped, pushed,
tracking, transfer) are status-invariant per SPEC §5.3 no-regress. Already-pushed rows
stay as-is and must be corrected in YNAB by hand; YNAB deduplicates on `import_id` so
the historical push stands.

**YNAB-side mislabel confirmed (§0).** The string "Bugetul de Stat RO" on the 13
historical rows is a YNAB-side fuzzy payee auto-match against the operator's
pre-existing payee, not a Spendula-produced value. Verified by `git log -p --all -S
"Stat RO"` returning empty. The current resolver emits `ACME SRL` at L2 via
the `beneficiary-first` rule for those rows — correct behavior; no existing code path
is unwound.

**SPEC §8 FX divergence.** SPEC §8 loosely describes the EUR side of an FX own-account
move as "tagged as transfer". GH #14 supersedes that: cross-currency own-account moves
stay `fetched` (status_unchanged) with the FX label payee. Reason: YNAB cannot model
a clean cross-currency transfer; the EUR outflow is a genuine reviewable budget event.
The operator manages the RON inflow on the other account independently.

**Alternatives considered.**

1. *IBAN lookup inside `Resolver`.* Rejected: `Resolver` is contractually pure / no-DB
   (`Resolver.php` docblock), tested as a pure unit (no DB). Adding a DB call breaks
   the contract and couples the resolver to Eloquent.
2. *New resolution level (L5 "own-account").* Rejected: changes the operator-facing
   review display, breaks the 0-4 histogram in `CounterpartyRecomputeCommand`, and the
   `counterparty_resolution_level >= 4` guard in `PayeeRuleRecorder`. The IBAN match
   is a post-resolution override, not a new resolution tier. Resolution level stays at
   whatever the resolver produced (preserving the L0–L4 histogram unchanged).
3. *Single combined payee for same-currency and FX.* Rejected: a same-currency
   own-account transfer is structurally different from an FX move and warrants a
   different status (`transfer` vs `fetched`). Collapsing them would force the operator
   to re-decide every FX move that YNAB receives under the transfer payee.

**Constraints.** `bank_accounts.iban` is nullable and NOT unique (schema has no
unique constraint on that column). Silent map-key collision would silently misclassify.
The `count === 1` guard prevents any ambiguous match from overriding.

**Consequences.**

- **Easier:** own-account transfers and FX moves now get correct payees and status
  automatically on sync; no manual operator action needed for new rows.
- **Easier:** `counterparty_resolution_level` histogram is untouched — the recompute
  command's before/after display is not perturbed by the new classification path.
- **Harder:** already-pushed own-account rows (the 13 historical ones) must be
  corrected manually in YNAB. `spendula:counterparty:recompute` updates the local DB
  row but cannot update the YNAB-side payee after the push.
- **Harder:** a future bank that encodes its own transfers in a format other than
  "To account, <IBAN>" (e.g., `internal_transfer_id` field) requires a new extraction
  path in `OwnAccountClassifier::extractDestinationIban()`. The current free-text
  patterns are ING-RO–specific; structured `creditor_account.iban` is universal.

---

## 2026-06-19 — FX own-account moves reversed to transfers (GH #14 follow-up)

**Decision.** Reverse the 2026-06-18 FX behavior: cross-currency own-account moves
now resolve to a transfer (`counterparty_name = "<prefix> : <dest>"`, `status = transfer`)
exactly like same-currency own-account moves. The `SPENDULA_OWN_ACCOUNT_FX_PAYEE` config
key and env var are removed.

**Why the reversal.** The operator's budget is single-currency EUR. Foreign-currency own
accounts (e.g. an ING RON account) are held in YNAB as EUR-equivalent tracking accounts.
The EUR debit on the source side is the budget event — it should be booked as a transfer
to the matching EUR-equivalent account, not as a "Currency Exchange" outflow. This matches
the operator's established manual convention (EUR amount at source, original amount + rate
in the YNAB memo). The prior decision's stated reason ("YNAB cannot model a clean
cross-currency transfer") was incorrect for this budget setup: the tracking account IS the
EUR-equivalent, so a native YNAB transfer pair is both correct and desired.

**Memo enrichment.** When the EB payload carries a `currency_exchange` object (SPEC §5.6
— populated by some banks on cross-currency transactions), `MatchUpdateOrInsert` appends
a compact `[FX] <orig_amount> <orig_ccy> @ <rate>` suffix to the remittance memo. This
preserves the original-currency detail the operator previously added by hand. If the field
is absent (ING-RO free-text rows do not carry it; they encode everything in
`remittance_information`), the memo is left as-is — no fabrication.

**Alternatives considered.**

1. *Keep FX as fetched with a "Currency Exchange" payee.* The original 2026-06-18
   decision. Reversed because it misrepresented the budget model: the FX outflow is not
   a payee transaction, it is a transfer between own EUR-equivalent accounts.
2. *Add a separate FX-transfer status.* Rejected: unnecessary complexity. The existing
   `transfer` status is correct; YNAB handles the pair semantics via the [TRANSFER] memo
   prefix and the operator's manual pairing step (SPEC §8 v1 model).

**Constraints.** `OwnAccountClassification.sameCurrency` is retained (not removed) so
callers can still branch on it for memo enrichment; removing it would be a larger API
change with no material benefit at this scale.

**Consequences.**

- **Easier:** all own-account moves (same-currency and FX) now land as transfers
  automatically; the operator no longer sees "Currency Exchange" rows in the review queue.
- **Easier:** `spendula:counterparty:recompute` backfills FX rows that were previously
  left at `fetched` / "Currency Exchange" up to `transfer` / "Transfer : <dest>".
- **Harder:** `SPENDULA_OWN_ACCOUNT_FX_PAYEE` is no longer a supported env var (removed
  from config and `.env.example`). Operators who set it explicitly must remove it from
  their `.env`; it will be silently ignored if left in place (the config key is gone).
- **Harder:** already-pushed FX own-account rows that landed as "Currency Exchange" must
  be corrected in YNAB manually. `spendula:counterparty:recompute` updates the local row
  but cannot retroactively change the YNAB-side payee after push.
