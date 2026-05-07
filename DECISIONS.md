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

- Revolut writes the dirty merchant string directly into EB's structured `creditor.name` (e.g. `Bolt.euo2604281114`), so the rule engine has to be reachable from L0/L1 — there is no remittance to extract from.
- The existing `rules` list semantics and tests are a recently shipped contract (PR #34, ING-RO PRs #32/#37). Layering a second list keeps that contract untouched.
- The `Rule` type is a final readonly value class; adding a `target` discriminator would break its single-purpose shape.

**Consequences.**

- **Easier:** name-side cleanup for any bank, by adding a `name_rules` array to its JSON. No code changes required to onboard another bank.
- **Easier:** the L2-only `rules:add` and `rules:test` artisan commands keep working unchanged — they touch only the `rules` array.
- **Harder:** rules that need to inspect *both* a name and a remittance simultaneously (e.g. rewrite the holder-name to `ATM withdrawal` only when the remittance starts with `Cash at`) cannot be expressed in `name_rules` as designed. That's the deferred ATM case from the issue. Solving it cleanly needs a schema extension (rule predicate over a second field) and is its own follow-up issue.
- **Slightly worse:** the `RuleLoader` now caches two lists per bank slug (the `forBank` cache for `rules` and the `nameCache` for `name_rules`). Two cache misses on first access for a bank that consults both. Acceptable for v1; collapse into one bundled load if it ever shows up in profiling.

