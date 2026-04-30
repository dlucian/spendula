# Latest task summary

## GH issue #8 — Phase 3a: Frankfurter exchange-rate client (SPEC §5.5)

### What changed

- `app/Services/ExchangeRates/RateProvider.php` — new interface with one
  method `getRate(string $base, string $quote, CarbonInterface $date): Rate`.
  Defines the seam for the eventual `tracking:snapshot` consumer in
  phase 3c. Behaviour contract documented in the interface docblock
  (success, failure modes, and the implicit weekend roll-back).
- `app/Services/ExchangeRates/Rate.php` — `final readonly` value object
  carrying `base`, `quote`, `rateDate` (CarbonImmutable, normalised to
  start-of-day), `rate` (string, full precision for bcmath), `source`.
- `app/Services/ExchangeRates/FrankfurterClient.php` — only
  `RateProvider` impl today. Endpoint shape verified live against
  `api.frankfurter.dev`: `GET /{YYYY-MM-DD}?base={X}&symbols={Y}` →
  `{"date": "...", "rates": {"Y": <number>}}`. Uses the existing
  `exchange_rates` table as cache. Lookup is bounded: business-day
  requests need an exact `rate_date` match, while weekend requests
  fall back to the most recent rate_date within 2 days. This stops a
  cached Friday row from indefinitely pinning later weekday lookups
  (Tuesday must fetch Tuesday, not serve Friday). Weekday holidays
  miss → fetch → Frankfurter rolls back → unique-constraint absorbs
  the duplicate insert. 5xx retry ladder mirrors
  `Ynab\Client::RETRY_DELAYS_MS_5XX` (`[2_000, 8_000]` ms). Concurrent
  inserts collide on the `(base_currency, quote_currency, rate_date,
  source)` unique constraint; the loser catches the Postgres `23505`
  and continues with the in-memory `Rate`.
- `app/Services/ExchangeRates/Exceptions/{ExchangeRateException,
  ExchangeRateProviderUnreachableException,
  ExchangeRateUnavailableException}.php` — typed exception ladder
  matching the Ynab/EnableBanking client patterns. Unreachable =
  transport failure or non-2xx after retries (SPEC §5.5 hard fail);
  unavailable = 200 with malformed body (defensive, surface rather
  than coerce).
- `app/Providers/AppServiceProvider.php` — singleton binding of
  `RateProvider::class` resolved off `config('spendula.exchange_rates.provider')`.
  Today only `frankfurter` is recognised; unknown values throw
  `RuntimeException` with operator-actionable copy.
- `config/spendula.php` — added `exchange_rates.base_url`
  (default `https://api.frankfurter.dev/v1`) so tests can swap host
  without monkey-patching the client.
- `.env.example` — added commented `SPENDULA_EXCHANGE_RATE_BASE_URL`
  example so operators discover the override exists.
- `tests/Feature/Services/ExchangeRates/FrankfurterClientTest.php` —
  eight tests covering: happy path, cache hit (one HTTP call across
  two `getRate` calls), weekend fallback (Saturday request resolves
  to Friday's `rate_date`; subsequent Saturday call hits cache via the
  `<=` lookup), 5xx-after-retries → unreachable, transport failure →
  unreachable, malformed 200 → unavailable, unknown-provider config
  throws on resolve, default config wires `FrankfurterClient`.

### Assumptions made

- **Frankfurter URL/response shape from live probe.** The original
  issue body suggested `?from=X&to=Y`; live `curl` against
  `api.frankfurter.dev` confirmed the correct query is
  `?base=X&symbols=Y` with response `{"amount":1.0,"base":...,
  "date":"YYYY-MM-DD","rates":{"Y":<number>}}`. Codified this shape in
  `FrankfurterClient::decode()` and the test fixtures.
- **Cache fallback gated on weekend-or-not.** A repeated weekend
  request returns the same earlier-business-day rate without
  re-probing (gap up to 2 days). Business-day misses always fetch.
  ECB-observed weekday holidays therefore cost one extra HTTP per
  unique date, but the unique constraint absorbs the resulting
  duplicate insert when Frankfurter rolls back to a date already in
  cache. See Open threads.
- **Decimal(18,8) round-trips Frankfurter's 5-decimal rates as
  `0.19641000`.** Tests cache-hit equality via `bccomp(.., 8)` rather
  than string equality so the storage canonicalisation is asserted
  honestly.
- **No advisory lock for concurrent fetch.** Two concurrent missers
  both fetch and the second insert hits the unique constraint;
  caught and swallowed, the in-memory `Rate` returned anyway. Acceptable
  because exchange rates are operationally idempotent and `tracking:snapshot`
  is single-shot.
- **YNAB / Enable Banking flows untouched.** No callers wired yet —
  this is the seam, not the consumer.
- Tests run against Postgres via `RefreshDatabase`. Postgres session
  timezone was UTC for the test run (default config).

### Blast radius

- Adds three classes under a new namespace and one service binding.
  No existing call sites changed.
- Future consumer (`tracking:snapshot` in #3c) will type-hint
  `RateProvider` and pick up `FrankfurterClient` automatically. If a
  second provider is later added, only `AppServiceProvider`'s `match`
  expands.
- The `exchange_rates` table is now actually written to. Until #3c
  lands, only the test suite exercises this path in production.

### Open threads

- **Conversion helper / `tracking:snapshot` integration** lives in
  phase 3c. The interface signature is provisional until a second
  caller exists.
- **Cache-fallback policy is heuristic.** The weekend gate covers
  Saturday/Sunday cleanly but treats ECB-observed weekday holidays
  (Easter Monday, Christmas, etc.) as cache misses, costing one extra
  HTTP per unique date. Re-running a snapshot the same day will
  reuse a rate even if Frankfurter has since published a fresher one.
  If the operator ever wants forced refresh, add a flag to
  `RateProvider::getRate` (or a parallel `forceFetch` method).
- **`decimal(18,8)` truncation** for any future provider with 8+
  decimal precision. Not blocking; flagged for the second-provider PR.
- **No second `RateProvider` impl yet.** The interface seam is real
  but unproven. If a future provider needs more than `(base, quote,
  date)` (e.g. configuration blob, auth token), the interface
  signature changes — accepted risk to avoid speculative design.
