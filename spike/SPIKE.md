# Spendula Spike — Specification

## Purpose

This is a **throwaway proof-of-concept**, not a production codebase. The goal is to de-risk the two unknowns in the future Spendula architecture:

1. Can we authenticate and pull transactions from a European bank via Enable Banking's PSD2 API?
2. Can we push those transactions into YNAB via its API?

If both work end-to-end, Spendula becomes a "buy YNAB, build a pipe into it" project. If either fails, we redesign. Either way, burn this repo when done — the value is the **learning**, not the code.

## Scope — explicitly narrow

### In scope
- Minimal PHP scripts, invoked from the CLI, one per milestone.
- Sandbox environments only (Enable Banking Sandbox, a throwaway YNAB trial budget).
- Happy-path only. One bank, one currency, a handful of transactions.
- Just enough error visibility to debug when things break (dump HTTP status + body).

### Out of scope
- Laravel, frameworks, service containers, dependency injection.
- Database persistence. Files on disk (JSON) for anything that needs to survive between scripts.
- Tests. PHPUnit, PHPStan, any linting.
- Error handling beyond "dump it and die".
- EUR conversion. Sandbox bank returns one currency; match YNAB budget to it.
- Dedup logic. The pipeline is prove-it-works, not prove-it's-correct-at-scale.
- Multi-bank. One sandbox ASPSP is enough.
- The 90-day re-auth flow (we know it exists; that's a Spendula concern).
- Any UI. CLI output only.
- Any code quality concerns — readable over clever, but no over-engineering for "future-proofing".

## Environment

- **OS**: macOS Sequoia on M1 Pro
- **PHP**: whatever `php -v` returns (should be 8.2+)
- **Package manager**: Composer
- **Working directory**: `~/p/spendula-spike` (already initialized)

## Existing files

```
~/p/spendula-spike/
├── private.key              # RSA private key (PKCS#8 PEM), 2048-bit
├── public.crt               # Self-signed X.509 cert, uploaded to Enable Banking
├── composer.json            # Has firebase/php-jwt:^6.0 as only dep
├── vendor/                  # Installed
└── 01-hello.php             # Milestone 1, already verified working (HTTP 200)
```

## Credentials (to be placed in `.env`, read via `parse_ini_file`)

```ini
ENABLEBANKING_APP_ID=9a540fc0-3f90-4728-97ad-d2755a1963fd
YNAB_ACCESS_TOKEN=<personal access token, to be added>
YNAB_BUDGET_ID=<test budget ID, to be discovered via milestone 4>
```

`.env` must be gitignored. No secrets in committed files.

## Milestone ladder — stop and verify at each

Each milestone is a self-contained PHP script. Do not merge milestones into classes, services, or shared helpers. A small shared file for reading `.env` and making JWT-signed HTTP calls is fine (`lib.php`), but nothing more.

### Milestone 1 — Enable Banking auth works ✅ DONE

`01-hello.php` — `GET /application`, returns HTTP 200 with app metadata.

### Milestone 2 — Discover available sandbox banks

`02-aspsps.php`

- `GET https://api.enablebanking.com/aspsps`
- Filter response to `country=FI` (or wherever the sandbox bank lives)
- Print a table of `name`, `country`, `psu_types`, `auth_methods` for each ASPSP
- **Goal**: identify which sandbox ASPSP to use in milestone 3. The sandbox uses a fake bank (typically called "Nordea" or similar in FI with a sandbox indicator). Pick the one clearly marked as sandbox-testing.

**Stop and confirm** before proceeding: which ASPSP did you pick? (name + country)

### Milestone 3 — Start a bank auth session

`03-auth.php`

- Build a `POST /auth` request with the chosen ASPSP, `psu_type=personal`, `access_scope=[balances, transactions]`, `valid_until` = 90 days from now, `state` = a random UUID, `redirect_url` = `http://localhost:8000/callback`.
- Print the returned `url` (Enable Banking's hosted auth page).
- Print the `state` — save it to `.state.json` in the working dir so milestone 4 can read it.
- Manually: open the URL in a browser, go through the sandbox bank's fake login (credentials given by Enable Banking — typically something like `user: psu-successful`), approve consent, get redirected to `localhost:8000/callback?code=...&state=...`.
- Since nothing is listening on localhost:8000, the browser will show a connection error. **This is expected.** Copy the `code` parameter from the browser's URL bar.

**Stop and confirm** before proceeding: paste the `code` as an argument to milestone 4.

### Milestone 4 — Exchange code for session, list accounts

`04-session.php <code>`

- Take the auth code as CLI arg.
- `POST /sessions` with `{"code": "<code>"}`.
- Save the full response (including `session_id` and list of accounts) to `.session.json`.
- Print the accounts: `account_id`, `iban`, `currency`, `name`.

**Stop and confirm**: you see at least one account.

### Milestone 5 — Fetch transactions

`05-transactions.php`

- Read `session.json`, pick the first account.
- `GET /accounts/{account_id}/transactions?date_from=<30 days ago>`
- Print count and a formatted table: date, amount, currency, creditor/debtor name, remittance_information.
- Save raw JSON response to `.transactions.json`.

**Stop and confirm**: you see sandbox transactions.

### Milestone 6 — YNAB authentication works

`06-ynab-hello.php`

- Prerequisite (manual): sign up at ynab.com for 34-day trial. Create a throwaway budget named `spendula-spike`. Add one EUR on-budget account named `Test Account`. Generate a Personal Access Token at account.ynab.com → Developer Settings. Put it in `.env`.
- `GET https://api.ynab.com/v1/budgets` with `Authorization: Bearer <PAT>`.
- Print the budgets list.
- Save the `spendula-spike` budget's ID to `.env` as `YNAB_BUDGET_ID`.
- Then `GET /budgets/{id}/accounts` and print the `Test Account` ID — save it as `YNAB_ACCOUNT_ID` in `.env`.

**Stop and confirm**: both IDs saved.

### Milestone 7 — Push one hardcoded transaction to YNAB

`07-ynab-push-one.php`

- `POST /budgets/{YNAB_BUDGET_ID}/transactions` with a hardcoded payload: today's date, amount `-12340` (YNAB uses milliunits: -€12.34), payee_name `"Spike Test"`, memo `"hardcoded test from milestone 7"`, cleared `cleared`.
- Print HTTP status and response.
- **Verify in YNAB UI**: log in, see the transaction in Test Account.

**Stop and confirm**: transaction visible in YNAB web UI.

### Milestone 8 — End-to-end pipeline

`08-pipeline.php`

- Read `transactions.json` from milestone 5.
- Transform each Enable Banking transaction into YNAB shape:
  - `account_id` = `YNAB_ACCOUNT_ID`
  - `date` = Enable Banking `booking_date`
  - `amount` = Enable Banking `transaction_amount.amount` × 1000 (milliunits), negated if `credit_debit_indicator == "DBIT"`, positive if `CRDT`
  - `payee_name` = `creditor.name` or `debtor.name` (whichever is present, truncated to 50 chars)
  - `memo` = original currency + amount + remittance info (truncated to 200 chars)
  - `cleared` = `cleared`
  - `import_id` = a deterministic hash: `"SPIKE:" . substr(sha1($date . $amount . $payee), 0, 30)` (YNAB's dedup field, max 36 chars, starts with a prefix so we can find/delete spike transactions later)
- `POST /budgets/{id}/transactions` with `{"transactions": [...]}` (bulk endpoint).
- Print count of created vs duplicate.
- **Verify in YNAB UI**: transactions visible.

**This is the moment the architecture becomes real.** Everything past this is polish.

## House rules for the spike

1. **No premature abstraction.** If two scripts both read `.env`, copy the three-line helper rather than building a config class.
2. **Hardcode fearlessly.** ASPSP name, sandbox credentials, redirect URL — all fine as constants inline. We are not shipping this.
3. **Print, don't log.** `var_dump`, `print_r`, `echo json_encode(..., JSON_PRETTY_PRINT)`. No Monolog.
4. **Fail loud, fail early.** `exit(1)` on any non-2xx HTTP response after dumping the body. No silent catches.
5. **Commit after each green milestone.** `git init` at the start; commit per milestone with a message like `milestone 3 green`. Makes it easy to back out if a later milestone breaks an earlier assumption.
6. **Ask before adding dependencies.** The only packages expected are `firebase/php-jwt` and maybe `guzzlehttp/guzzle` if raw curl becomes painful. Do not bring in Laravel, Symfony components, or anything framework-ish.
7. **The `.env`, `.state.json`, `.session.json`, `.transactions.json` files are all gitignored.** They contain secrets or session data.

## Definition of done

The spike is complete when `php 08-pipeline.php` reads a `transactions.json` produced by milestone 5 and creates those same transactions in the YNAB test budget, visible in the web UI, with no duplicates on a second run.

At that point: write a short `FINDINGS.md` covering what was surprising, what broke, what rate limits looked like, how long the auth redirect flow took in practice, anything that will matter when designing the real Spendula. Then the repo can be archived.

## Out-of-band notes for the AI assistant

- Prefer running scripts and reading output over speculating about behaviour. The whole point of this spike is to observe real API responses.
- When an API call fails, dump the full request (method, URL, headers minus auth, body) and full response (status, headers, body) before diagnosing. Most bugs in API integrations are obvious once you can see both sides.
- Enable Banking's sandbox ASPSPs return deterministic fake data. If something looks odd (e.g., transactions with dates far in the past or future), it's probably by design — don't patch around it.
- YNAB milliunits: €12.34 is `12340`. Outflows are negative. Do not send string amounts; integers only.
- Never commit `.env`, `private.key`, `.session.json`, `.state.json`, `.transactions.json`.
