# Latest task summary

## `spendula:review --approve-all` for unattended cron pipeline (GH #22)

### What changed

- **`app/Services/Review/TransactionActions.php`** — new `bulkApproveAll(): int`.
  Single bulk UPDATE flipping every `status=fetched` row to `approved` (clearing
  `skipped_at`/`skip_reason`, stamping `updated_at`), regardless of resolution
  level or currency. Full behavioural docblock (success/side-effects/idempotency/
  concurrency). Mirrors `bulkApproveTrivial` minus the level and base-currency
  filters.

- **`app/Console/Commands/Spendula/ReviewCommand.php`**:
  1. `#[Signature]` gains `--approve-all`.
  2. `$approveAll` added to the auto-mutation gate, so `PayeeRuleEngine::applyRules`
     runs first (rules/own-account classifier win precedence).
  3. New `if ($approveAll)` block calls `bulkApproveAll()` after the rules pass and
     after the trivial block, reporting `Approved N fetched transaction(s).` It then
     **short-circuits and returns SUCCESS before `ReviewSession`** — the fetched pool
     is empty, so running the session would emit a "Nothing to review" notice plus a
     contradictory "Reviewed 0: approved=0 …" summary after the rows were approved
     (Copilot PR review). When rules also acted, a `Payee rules also applied:
     approved=X skipped=Y transferred=Z` detail line is printed so nothing is hidden.
  4. **Latent bug fix in `recomputeAutoApplyByAction()`**: `pluck('status')` returns
     cast `TransactionStatus` enum instances under Laravel 13, but the `match` compared
     against `->value` strings and silently bucketed everything as `default` — so the
     existing interactive/`--bulk-approve-trivial` `Reviewed N:` summary had been
     reporting `approved=0 skipped=0 transferred=0` for auto-applied rule rows. Now
     normalises enum→value before matching. Surfaced because this PR is the first to
     assert on those counts.

- **Tests**:
  - `TransactionActionsTest` — `bulkApproveAll` touches every fetched row (levels
    0/2, EUR + RON) and leaves approved/skipped/transfer rows untouched.
  - `ReviewCommandTest` — `--approve-all` approves mixed-level/mixed-currency
    fetched rows across two accounts and reports the count; and honors an
    operator-authored `skipped` payee rule (rule wins, its row is not swept).

- No migration, no new config, no route change.

### Assumptions made

- **Push selection is unchanged and correct**: `PushRunner` already pushes
  `approved` + `transfer` and excludes `transfer_dropped`/`skipped`/`fetched`
  (`PushRunner.php:62`), so `--approve-all` → `spendula:push` needs no push-side
  change. Verified by reading, not by a live YNAB push (tests exercise the review
  transition only).
- **Rules-before-bulk ordering is the intended precedence** — same contract the
  existing `--bulk-approve-trivial` path relies on; a `fetched` row moved to
  `skipped`/`transfer`/`approved` by `applyRules` has already left the pool the
  bulk UPDATE targets.
- **Non-TTY safety preserved**: mutation still only happens when a flag is passed;
  plain non-TTY `spendula:review` remains a no-op.
- Postgres session timezone UTC during the run (default config). No OAuth/EB or
  YNAB network interaction in this change.

### Blast radius

- `spendula:review` gains one flag; the no-flag and `--bulk-approve-trivial`
  behaviours are untouched (existing tests still green: 526 passed).
- `TransactionActions` gains one method; no existing method modified.
- Downstream: with `--approve-all` in a cron, **everything not otherwise
  classified reaches YNAB** as `approved`. The operator's only remaining pre-YNAB
  filter is payee/counterparty skip rules — anything not covered by a rule (and
  not an own-account transfer) will be pushed. Foreign-currency rows are now
  approved too (unlike the trivial flag). This is the intended trade: YNAB's own
  unapproved-transaction gate becomes the review surface.

### Open threads

- No cron/systemd unit is shipped; the operator wires
  `spendula:sync && spendula:review --approve-all && spendula:push` into host
  cron themselves (matches the "no scheduler" architecture constraint).
- If a funding-leg transfer is pushed before its cross-source pair syncs,
  `CrossSourceTransferLinker` still logs the manual-convergence warning (GH #16
  behaviour, unaffected by this change).
