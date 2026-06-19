# Changelog

## [Unreleased]

### Fixed

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
