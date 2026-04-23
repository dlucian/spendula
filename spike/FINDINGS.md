# Spendula Spike — Findings

The spike proved end-to-end feasibility: Enable Banking sandbox → Spendula → YNAB, with idempotent re-imports. This document captures what was surprising, what broke, what to watch for when designing the real Spendula, and what has no business going into production code.

## Scorecard

| Milestone | Outcome |
|---|---|
| 1 — EB auth (`GET /application`) | ✅ first try |
| 2 — List ASPSPs | ✅ first try |
| 3 — Start auth session | ✅ first try (request shape), but consent flow failed twice before working — see below |
| 4 — Exchange code for session | ✅ first try |
| 5 — Fetch transactions | ✅ first try |
| 6 — YNAB auth | ✅ first try |
| 7 — Push one hardcoded txn | ✅ first try |
| 8 — Full pipeline w/ dedup | ✅ first try on create; second run correctly reported 1 duplicate, 0 created |

Total wall time: ~90 min including three abandoned consent flows and account creation in Mock ASPSP.

## Enable Banking — what was surprising

### 1. Mock ASPSP starts empty, not seeded

Expected: sandbox bank ships with 1–2 fake accounts and a handful of transactions.

Reality: a freshly-onboarded PSU (the Enable Banking developer account itself — the sandbox conflates "developer" and "end user") has **zero mock accounts**. The consent flow still *succeeds* with zero accounts — but Enable Banking then fails to finalize the session and returns `?error=server_error` on the callback **with no `error_description` query param**. Opaque and misleading; the actual fix is to go to `https://enablebanking.com/cp/mock-aspsp/auth?...`, click "Create Account", and provision at least one mock account before retrying the auth flow.

**This is a prerequisite that should be pinned in developer setup docs for the real Spendula's sandbox environment.**

### 2. The redirect-URL pattern in Enable Banking's public examples is outdated

Our app had `/banking/callback` registered; the spec and most Enable Banking examples use `/callback`. The registered URL is authoritative — if mismatched, the redirect fails silently on Enable Banking's side (before ever reaching the client). Check `GET /application` for `redirect_urls` and use one of those exactly.

### 3. `access_scope` is no longer a field

Spec (and older docs) suggest sending `access_scope=[balances, transactions]` in the `/auth` body. **Omitting the whole scope field and sending only `access.valid_until` worked with no complaint.** Enable Banking appears to default to "all available scopes" when none are specified. The real Spendula can use the per-account scope filters inside `access.accounts` / `access.balances` / `access.transactions` if fine-grained scoping is ever needed (it isn't, for our use case).

### 4. Auth-session TTL is short and non-obvious

The `sessionid` in the `tilisy-sandbox.enablebanking.com/ais/start?sessionid=…` URL expires well under 10 minutes. On the second attempt, the user created Mock accounts *during* the consent flow — by the time they clicked "Authorize" on the account-selection screen, the auth session had timed out and Enable Banking returned `Error while updating authentication status` on a fresh page. **Work through the flow promptly, and in the real Spendula, build in a "session expired, restart" detection + graceful retry.**

### 5. Mock's account data is semantically wrong, and production banks won't be

Multiple places where Mock ASPSP diverges from real bank behavior:

- **Account names**: what you type in the Mock creation form ("Peace of Mind", "Abundance") is **not preserved** in AIS responses. The API returns generic Finnish persona names ("Onni Nieminen", "Akseli Virtanen") instead. Real banks will return the customer's actual account label. Don't rely on `name`.
- **`account_id` and `iban` are `null`** for Mock; `uid` is the only handle. Real banks will populate `account_id.iban` (and probably `account_id.bban`, `masked_pan` for cards). Handle both shapes.
- **`creditor` / `debtor` semantics are inverted**: Mock puts the counterparty in `creditor.name` regardless of transaction direction, leaving `debtor` always `null`. SEPA convention is *opposite*: for a CRDT (inbound) transaction, the counterparty is the debtor. **For real banks we must use direction-aware logic**: `CRDT → debtor.name`, `DBIT → creditor.name`. The spike's `creditor ?? debtor` fallback only works because Mock always uses `creditor`.
- **`cash_account_type: CARD`** for what should be a regular current account. Real banks will return `CACC`, `SVGS`, `CARD`, `LOAN` etc. — Spendula may want to filter or classify based on this.
- **`transaction_id` and `reference_number` are `null`**. Only `entry_reference` (5-char opaque string like `"uxr2h"`) is populated. The spec's dedup-via-sha1-hash strategy was the correct call; we cannot rely on bank-side transaction IDs.
- **Exactly 1 transaction per account, auto-generated once.** Doesn't grow over time, doesn't reflect activity. Expand the test fixture if needed by creating additional accounts in `cp/mock-aspsp` — each new account gets its own seed transaction.

### 6. `identification_hash` / `identification_hashes` — worth understanding now

Each account in the `/sessions` response carries:
```
"identification_hash": "<base64>.<base64>",
"identification_hashes": [<hash_1>, <hash_2>]
```

These are **Enable Banking's stable cross-session identifiers**, calculated from ASPSP + account resource ID (and a secondary hash from ASPSP + account name). When the 90-day consent expires and the user re-authenticates, the new session's accounts carry the same `identification_hash` — that's how Spendula will match "this re-auth is the same physical bank account as before". Out of scope for this spike, but **critical for the real Spendula**: store the hash with the local account record, not the per-session `uid`.

### 7. `transaction_amount.amount` is a string

`"4.77"` not `4.77`. Explicit `(float)` cast needed before milliunit math. For production, use arbitrary-precision decimal handling (`bcmath` or a Money library) — float×1000 drifts at unfortunate values (e.g. `0.10 × 1000` → `99.99999999…`). The spike uses `(int)round()` which papers over it.

### 8. Auth URL is on `tilisy-sandbox.enablebanking.com`, not `api.enablebanking.com`

The consent UI is served from a separate subdomain (`tilisy` = Finnish "account opening", Enable Banking's legacy brand). Production would be `tilisy.enablebanking.com`. Good to know if we're ever debugging network issues or CSPing the iframe.

## YNAB — what was surprising

### 1. Everything lives under `data`

Every response body wraps payloads in `{data: {...}}`. Not a big deal, just repetitive unwrapping. Spendula's HTTP client should probably auto-unwrap.

### 2. API-created transactions default to `approved: false`

YNAB treats every API-pushed transaction as "needs user review" and surfaces them in the Needs-Review queue. For Spendula, **this is actually the right UX** — users see what we imported before it hits their running balance calculations. We can opt-out with `approved: true` in the payload but shouldn't by default.

### 3. Bulk endpoint returns 201 even on full-duplicate runs

`POST /budgets/{id}/transactions` with `{"transactions": [...]}` returns **HTTP 201 whether any rows were created or not**. The authoritative dedup signal is the `duplicate_import_ids` array in the response body. **Don't use status code for any "did anything import" logic.**

### 4. Payees are auto-created and auto-deduped

Passing `payee_name` causes YNAB to match against existing payees by normalized name, and create a new one if no match. No separate payee management API needed. Stable `payee_id` comes back in the response.

### 5. `import_payee_name` vs `import_payee_name_original`

YNAB keeps a separate "as-imported" payee name (`import_payee_name`) from the user-renamed version. Helpful for reconciliation and for understanding what a bank originally sent. `import_payee_name_original` is only populated when the user has renamed a payee via YNAB's rename rules — it captures the *pre-rename* string.

### 6. `import_id` strictly 36 chars max

The spec's recipe `"SPIKE:" + substr(sha1(...), 0, 30)` produces exactly 36. If we'd used `substr(...,0,31)` or forgotten the prefix was 6 chars, it would have failed at runtime. YNAB **silently refuses longer import_ids** (we didn't test this but it's documented). For production, budget this conservatively — a bug here means silent dedup failures.

### 7. No auto-categorization without history

New payees import with `category_id: null` and show a "This needs a category" pill. YNAB's auto-categorization is payee-history-based: after a user categorizes a few transactions for the same payee, YNAB learns and auto-suggests. **For real Spendula**, three options:
- Leave blank (current behavior, matches YNAB's intended UX)
- Implement per-payee category rules Spendula-side (more work, but lets us pre-categorize common payees like "Salary → Income")
- Allow users to pass a default `category_id` for a whole import batch

Probably start with option 1; add option 2 if users ask.

### 8. No rate limit issues observed

YNAB docs specify 200 req/hr/token. The spike used ~8 calls — nowhere near the limit. For a real Spendula, 200 req/hr is plenty per user (one poll every ~20 seconds wouldn't hit it), but if we ever add batch backfill across many budgets, we may need to rate-limit-aware retry.

## What broke

1. **First consent attempt**: `error=server_error` (no description) — cause was zero mock accounts on the PSU.
2. **Second consent attempt**: `Error while updating authentication status` — cause was sessionid TTL expired during the account-creation detour.
3. **Third consent attempt**: ✅ worked.

Two failures were both explained by the "Mock ASPSP is empty and mutable during the flow" quirk. In production, neither failure mode applies (real PSUs already have real accounts). But the `server_error`-with-no-description response is genuinely bad UX on Enable Banking's side — worth reporting to them.

## For the real Spendula — design implications

1. **Use `identification_hash` as the stable account anchor.** Never the per-session `uid`. Store it at account-link time; match on re-auth.
2. **Direction-aware counterparty extraction.** `DBIT → creditor.name`, `CRDT → debtor.name`, fallback to whichever is non-null.
3. **String-amount handling**: use `bcmath` or a Money class. Never `float × 1000`.
4. **Dedup via hash, not bank ID.** Mock doesn't give us bank IDs; even real banks sometimes rotate or repeat them. Hash(date + signed_amount + payee) is resilient.
5. **Import-ID prefix scheme**: keep the `SPIKE:` / `SPNDL:` prefix convention. Lets us find and batch-delete application-created transactions if we ever need to purge-and-reimport for a user.
6. **Handle `session_error` retries gracefully.** Auth sessions expire; surface "session expired, please re-authenticate" with a one-click retry rather than propagating an opaque OAuth error.
7. **Store the full Enable Banking response JSON per account link.** Field shapes differ per bank; having the raw is cheap insurance when a new bank throws an unexpected key.
8. **YNAB's `approved: false` default is good UX — keep it.** Don't pre-approve imports.
9. **Don't trust Mock for semantic correctness.** It lies about `creditor`/`debtor` direction and returns synthetic data in `name` / `remittance_information`. Use it for happy-path structural tests only; use real-bank fixtures for any business-logic testing.

## What this spike deliberately did NOT prove

- Real bank integration (Nordea, OP, S-Pankki). Known unknowns: likely some auth-method variations, real account_id population, proper SEPA semantics.
- 90-day re-authentication flow. The spec acknowledged this.
- Multi-currency budgets (we had EUR+RON accounts but ignored RON). Spendula will have to decide whether one-user-one-currency is a product constraint or whether we support multi-currency budgets via separate YNAB budgets per currency.
- High-volume imports / pagination on EB transactions. 1 transaction returned.
- Error-case branching: pending transactions (`status: "PDNG"`), reversals, transfers between same-user accounts (YNAB has first-class transfer support — we didn't exercise it).
- Webhook / push-style updates from either side. Both APIs are pull-only as far as we used them.

## File inventory

- `01-hello.php` — EB auth check
- `02-aspsps.php` — list ASPSPs, identify sandbox
- `03-auth.php` — start auth session, emit URL
- `04-session.php <code>` — exchange code for session
- `05-transactions.php` — fetch txns for first EUR account
- `06-ynab-hello.php` — YNAB budget/account discovery
- `07-ynab-push-one.php` — hardcoded single-txn push
- `08-pipeline.php` — full EB→YNAB transform + bulk push + dedup
- `lib.php` — 3 functions: `env()`, `enablebanking_request()`, `ynab_request()`
- `.env`, `private.key`, `public.crt` — credentials (gitignored)
- `.state.json`, `.session.json`, `.transactions.json` — state between milestones (gitignored)

Archive candidate. Real Spendula starts from a blank slate using these findings, not this code.
