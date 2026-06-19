# Latest task summary

## Cross-source own-account top-up dedup + same-day import-dedup fix (GH #16)

### What changed

- **`app/Enums/TransactionStatus.php`** — added `TransferDropped = 'transfer_dropped'`.
  New terminal status for the Revolut-side CRDT leg after a cross-source top-up pair
  is linked. Excluded from push by `PushRunner`'s existing `whereIn('status', [Approved, Transfer])`.

- **`app/Models/Transaction.php`** — added `linked_transfer_id` nullable self-FK property
  and `linkedTransfer()` BelongsTo relation.

- **`app/Services/Sync/TopupLink.php`** — new value object capturing one own-account card
  top-up mapping (funding_bank_slug, card_last4, funding_marker, destination_account_ref,
  apple_pay_tokens[], amount_tolerance_days, resolvedDestinationId).

- **`app/Services/Sync/TopupLinkLoader.php`** — new service. Parses and validates
  `config/counterparty-rules-enabled/own-account-topups.json`; resolves
  `destination_account_ref` to a `bank_account_id` (by display_name or IBAN, active
  accounts only); caches per instance (same pattern as OwnAccountClassifier).

- **`app/Services/Sync/CrossSourceTransferLinker.php`** — new service. Invoked from
  `MatchUpdateOrInsert::apply` after every insert/non-dedup update. Detects funding-side
  DBIT (COMPRA <card> Revolut ...) and destination-side CRDT (Apple Pay Top-Up by *<token>)
  legs; searches for the counterpart within ±N days at the same amount+currency; on hit:
  promotes the funding leg to `status=transfer` + `Transfer : <Revolut acct>` counterparty
  + `linked_transfer_id`, marks the destination leg `transfer_dropped` + `linked_transfer_id`.
  Order-independent, idempotent. Already-pushed destination → funding promoted, destination
  left alone, `cross_source.late_pair` warning logged.

- **`app/Services/Sync/MatchUpdateOrInsert.php`** — two changes:
  1. *GH #16 dedup fix:* when exactly one fundamentals-match candidate exists but its raw
     counterparty differs from the incoming raw counterparty, force an insert (new occurrence)
     instead of deduping. Fixes two different-card same-day same-amount top-ups being silently
     merged.
  2. *Linker invocation:* added optional `CrossSourceTransferLinker $transferLinker` constructor
     param; `maybeLink()` called after every insert and non-Deduped update.

- **`config/counterparty-rules-available/own-account-topups.json`** — anonymised example
  config shipped for reference. Real values go in the `enabled/` copy.

- **`config/counterparty-rules-enabled/own-account-topups.json`** — operator-managed
  config (anonymised example committed; replace with real entries).

- **`config/spendula.php`** — added `own_account.topup_window_days` (default 3, overridable
  via `SPENDULA_OWN_ACCOUNT_TOPUP_WINDOW`).

- **`app/Services/Status/BankRow.php`** — extended `queuedCounts` type annotation to include
  `transfer_dropped` key.

- **`app/Services/Status/StatusSnapshotBuilder.php`** — `loadQueuedCounts()` now includes
  `transfer_dropped` in the queried statuses; `queuedCountsByStatus` has a `transfer_dropped`
  key zero-filled per bank.

- **`app/Services/Status/StatusRenderer.php`** — `renderQueuedCounts()` now shows a `Dropped`
  column in the queued-counts table.

- **`database/migrations/2026_06_19_100001_add_linked_transfer_and_transfer_dropped_status.php`** —
  adds `transactions.linked_transfer_id` (nullable self-FK, nullOnDelete, indexed); drops and
  recreates `transactions_status_check` to include `transfer_dropped`.

- **Tests:**
  - `tests/Unit/Services/Sync/TopupLinkLoaderTest.php` — new. Covers file-not-found, JSON
    parse errors, validation errors, resolution by display_name, resolution by IBAN, unknown
    ref → null, inactive account → null, caching, multiple links.
  - `tests/Feature/Services/Sync/CrossSourceTransferLinkerTest.php` — new. Covers both sync
    orderings, idempotent re-sync, already-pushed destination, no-config, outside-window,
    wrong-amount, descriptor mismatch, unknown token, within-window boundary, skipped tx.
  - `tests/Feature/Services/Sync/MatchUpdateOrInsertTest.php` — two regression tests added:
    `test_two_distinct_card_topups_same_day_same_amount_both_inserted` and
    `test_identical_topup_rows_still_dedup`.

- **`CHANGELOG.md`**, **`DECISIONS.md`**, **`SUMMARY.md`** — updated per convention.

### Key design choices

- Funding-bank leg is the survivor (not the Revolut leg) — see DECISIONS.md for rationale.
- Config file (Option A) vs DB table (Option B): config chosen for v1; DB upgrade path documented.
- `CrossSourceTransferLinker` is an optional parameter in `MatchUpdateOrInsert` — callers
  that don't inject it behave exactly as before.
- `transfer_dropped` is a terminal status; `PushRunner`'s existing filter excludes it without
  any code change.
