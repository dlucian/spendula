# Changelog

## [Unreleased]

### Fixed

- **Own-account transfers mis-pushed as external payees (GH #14).** Transactions whose
  destination IBAN belongs to one of the operator's own `bank_accounts` are now
  classified before reaching YNAB. Same-currency own-account transfers get
  `status=transfer` and `counterparty_name="Transfer : <destination display_name>"`
  (so PayloadBuilder prepends `[TRANSFER]` to the memo). Cross-currency (FX) moves get
  `counterparty_name="Currency Exchange"` (configurable via `SPENDULA_OWN_ACCOUNT_FX_PAYEE`)
  and remain at `status=fetched` for operator review. The IBAN lookup is direction-aware
  (DBIT → `creditor_account.iban` / "To account,"; CRDT → `debtor_account.iban` /
  "From account,") and guards against null/inactive/duplicate-IBAN accounts. Existing
  rows can be backfilled with `php artisan spendula:counterparty:recompute`; already-pushed
  rows must be corrected in YNAB by hand.
