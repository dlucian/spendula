# Changelog

## [Unreleased]

### Fixed

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
