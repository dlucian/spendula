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
