# Changelog

## [Unreleased]

### Fixed

- **Transfer payee rejected by YNAB + batch poisoning fix (GH #18).** `PayloadBuilder` now
  sanitizes `payee_name` before it reaches YNAB. Own-account transfer rows whose
  `counterparty_name` starts with `Transfer : ` have that prefix stripped (the destination
  name becomes the YNAB payee; the `[TRANSFER]` memo tag preserves the transfer semantics).
  The other three YNAB-reserved payee strings (`Starting Balance`, `Manual Balance Adjustment`,
  `Reconciliation Balance Adjustment`) fall back to a safe generic (`Own account transfer`).
  Separately, `PushRunner` now handles the case where YNAB returns a `400` error that names
  specific offending zero-based indices (`(index: N)` in the error detail): it strips only
  those rows and re-POSTs the remaining transactions in a single retry. Previously one bad
  row poisoned every row in the same batch; now only the genuinely-rejected row is logged as
  a `PushRunError` and the rest are pushed normally. When YNAB's error contains no parseable
  indices, or the retry also fails, the original fail-all behaviour is preserved.

- **Cross-source own-account top-up dedup (GH #16).** Card top-ups via Apple Pay
  generate two parallel entries: a DBIT on the funding bank ("COMPRA 5962 Revolut 2180
  Dublin IE") and a CRDT on Revolut ("Apple Pay Top-Up by *2798"). Without deduplication
  these register as independent transactions in YNAB, inflating outflows and overstating
  the Revolut balance. `CrossSourceTransferLinker` now links the pair: the funding-bank
  leg is promoted to `status=transfer` with a `Transfer : <Revolut account>` counterparty;
  the Revolut leg is marked `status=transfer_dropped` (new terminal status, excluded from
  push). The match key is `(funding_account, destination_account, |amount|, currency, ±N days)`;
  the account pair is resolved from a new operator-managed config file
  (`config/own-account-topups-enabled/own-account-topups.json`). Linking is order-independent
  and idempotent. A new self-FK `transactions.linked_transfer_id` connects both legs.
  `spendula:status` now shows a `Dropped` column in the queued-counts table.

- **Same-day/same-amount import-dedup false positive (GH #16 secondary).** Two top-ups
  on different cards on the same day with the same amount (e.g. "COMPRA 5962 …" and
  "COMPRA 9800 …") shared a normalized counterparty string and were collapsed — the
  second was silently deduped into the first. `MatchUpdateOrInsert` now compares the
  raw (pre-normalization) counterparty when exactly one normalized-match candidate exists;
  if the raws differ the incoming row is forced to a new occurrence insert instead.

- **Re-sync dedup regression when 2+ same-normalized rows exist (GH #16 codex follow-up).**
  `MatchUpdateOrInsert`'s `count() > 1` branch previously blind-inserted a new occurrence
  whenever two or more normalized-counterparty matches existed. A routine overlap re-sync of
  either existing row would therefore insert a spurious third occurrence. The branch now checks
  the incoming raw counterparty against each candidate's stored raw; if exactly one matches,
  that row is updated (deduplicated) instead. Only a genuinely new raw counterparty — one not
  matching any existing row — triggers the occurrence-increment insert.

- **Funding-leg pushed guard in cross-source linker (GH #16 codex follow-up).**
  `CrossSourceTransferLinker::applyLink` previously unconditionally promoted the funding leg's
  status to `transfer` before checking if it was already `pushed`, which would regress a row
  already sent to YNAB. The funding-pushed case is now guarded symmetrically: the funding leg
  is left entirely unmodified; the destination phantom is still suppressed (`transfer_dropped`)
  so YNAB never receives a standalone credit; a `cross_source.late_pair` warning is logged.

- **Deterministic, ambiguity-safe counterpart selection (GH #16 codex follow-up).**
  `CrossSourceTransferLinker::findDestinationCounterpart` and `findFundingCounterpart` now use
  `ORDER BY booking_date, id` for stable iteration and collect all descriptor-matching candidates
  before selecting. A single unique-closest candidate is returned; when two or more candidates
  are equidistant in date, a `cross_source.ambiguous_match` warning is logged and `null` is
  returned — leaving both legs unlinked for manual review rather than guessing on a financial
  pairing.

- **Own-account transfers mis-pushed as external payees (GH #14).** Transactions whose
  destination IBAN belongs to one of the operator's own `bank_accounts` are now
  classified before reaching YNAB. Both same-currency and cross-currency (FX) own-account
  moves get `status=transfer` and `counterparty_name="Transfer : <destination display_name>"`
  (so PayloadBuilder prepends `[TRANSFER]` to the memo). When the EB payload carries a
  `currency_exchange` field, the original-currency detail is appended to the memo as
  `[FX] <amount> <CCY> @ <rate>`. The IBAN lookup is direction-aware (DBIT →
  `creditor_account.iban` / "To account,"; CRDT → `debtor_account.iban` / "From account,")
  and guards against null/inactive/duplicate-IBAN accounts. Existing rows can be backfilled
  with `php artisan spendula:counterparty:recompute`; already-pushed rows must be corrected
  in YNAB by hand. The `SPENDULA_OWN_ACCOUNT_FX_PAYEE` env var is removed (was
  "Currency Exchange" default); remove it from any existing `.env` file.
