<?php

namespace App\Services\ExchangeRates;

use App\Models\ExchangeRate;
use App\Services\ExchangeRates\Exceptions\ExchangeRateProviderUnreachableException;
use App\Services\ExchangeRates\Exceptions\ExchangeRateUnavailableException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Frankfurter (https://api.frankfurter.dev) HTTP client. Endpoint shape:
 *   GET {base_url}/{YYYY-MM-DD}?base={BASE}&symbols={QUOTE}
 * Response: `{"amount":1.0,"base":"RON","date":"2026-04-24","rates":{"EUR":0.19641}}`.
 *
 * Frankfurter has no documented rate limit, so retry policy mirrors the
 * 5xx ladder used by `Ynab\Client`. 4xx (including 429, which Frankfurter
 * does not currently emit) collapses into the unreachable bucket.
 *
 * Cache strategy: the `exchange_rates` table is the cache. Lookup before
 * HTTP; on miss, fetch, persist, and return. The cache is keyed off the
 * resolved `rate_date` returned by the provider — which may be earlier
 * than the date the caller asked for (weekend/holiday roll-back). The
 * lookup therefore tries the requested date first, then falls back to
 * the most recent earlier `rate_date <= requested_date` for the same
 * `(base, quote)` so a second call for a Saturday does not re-probe the
 * provider.
 */
class FrankfurterClient implements RateProvider
{
    private const string SOURCE = 'frankfurter';

    private const array RETRY_DELAYS_MS_5XX = [2_000, 8_000];

    public function __construct(
        private readonly string $baseUrl,
    ) {}

    public function getRate(string $base, string $quote, CarbonInterface $date): Rate
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        $requested = CarbonImmutable::instance($date)->startOfDay();

        $cached = $this->lookupCached($base, $quote, $requested);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->fetch($requested, $base, $quote);
        $payload = $this->decode($response, $base, $quote);

        return $this->persist($base, $quote, $payload['rate_date'], $payload['rate']);
    }

    private function lookupCached(string $base, string $quote, CarbonImmutable $requested): ?Rate
    {
        // Two cases hit cache:
        //   1. Exact match on rate_date — the requested business day was
        //      previously fetched and stored.
        //   2. Weekend rollback — Frankfurter publishes only on business
        //      days, so a Saturday/Sunday request reuses the most recent
        //      cached rate dated within 2 days.
        //
        // Crucially, business-day misses do NOT fall back. Pinning a Tuesday
        // request to Friday's cached rate would serve stale data forever
        // once one weekend rate is cached. For non-weekend misses (including
        // weekday holidays) we fetch; if Frankfurter rolls back to an
        // already-cached date, the unique constraint in `persist()` swallows
        // the duplicate insert and we still return the resolved rate.
        $query = ExchangeRate::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('source', self::SOURCE);

        if ($requested->isWeekend()) {
            $query->whereBetween('rate_date', [
                $requested->subDays(2)->toDateString(),
                $requested->toDateString(),
            ])->orderByDesc('rate_date');
        } else {
            $query->where('rate_date', $requested->toDateString());
        }

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return new Rate(
            base: $base,
            quote: $quote,
            rateDate: $row->rate_date,
            rate: $row->rate,
            source: self::SOURCE,
        );
    }

    /**
     * @return array{rate_date: CarbonImmutable, rate: string}
     */
    private function decode(Response $response, string $base, string $quote): array
    {
        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (! is_array($body)
            || ! isset($body['rates'])
            || ! is_array($body['rates'])
            || ! array_key_exists($quote, $body['rates'])
            || ! isset($body['date'])
            || ! is_string($body['date'])
        ) {
            throw new ExchangeRateUnavailableException(
                "Frankfurter returned 200 but the response is missing the {$base}->{$quote} rate."
            );
        }

        $rate = $body['rates'][$quote];
        if (! is_int($rate) && ! is_float($rate) && ! is_string($rate)) {
            throw new ExchangeRateUnavailableException(
                "Frankfurter returned a non-numeric rate for {$base}->{$quote}."
            );
        }

        // Preserve full provider precision as a string. PHP json_decode hands
        // us a float for JSON numbers; cast through string to keep the
        // textual representation stable for bcmath downstream.
        $rateString = is_string($rate) ? $rate : (string) $rate;

        return [
            'rate_date' => CarbonImmutable::parse($body['date'])->startOfDay(),
            'rate' => $rateString,
        ];
    }

    private function persist(string $base, string $quote, CarbonImmutable $rateDate, string $rate): Rate
    {
        // Two concurrent callers can both miss cache and race to insert
        // the same (base, quote, rate_date, source) tuple. The unique
        // constraint guarantees one wins; on duplicate-key the loser
        // re-reads the row that the winner just inserted.
        try {
            ExchangeRate::query()->create([
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate_date' => $rateDate->toDateString(),
                'rate' => $rate,
                'source' => self::SOURCE,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        return new Rate(
            base: $base,
            quote: $quote,
            rateDate: $rateDate,
            rate: $rate,
            source: self::SOURCE,
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }

    private function fetch(CarbonImmutable $date, string $base, string $quote): Response
    {
        $url = rtrim($this->baseUrl, '/').'/'.$date->toDateString();
        $query = ['base' => $base, 'symbols' => $quote];

        $attempts = 1 + count(self::RETRY_DELAYS_MS_5XX);
        $response = null;
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::acceptJson()->get($url, $query);
                $lastConnectionError = null;

                if ($response->status() < 500) {
                    break;
                }
            } catch (ConnectionException $e) {
                $lastConnectionError = $e;
                $response = null;
            }

            if ($attempt < $attempts) {
                Sleep::for(self::RETRY_DELAYS_MS_5XX[$attempt - 1])->milliseconds();
            }
        }

        if ($response === null) {
            throw new ExchangeRateProviderUnreachableException(
                'Frankfurter transport failure after retries: '
                .($lastConnectionError?->getMessage() ?? 'unknown error')
            );
        }

        if ($response->status() >= 400) {
            throw new ExchangeRateProviderUnreachableException(
                "Frankfurter returned HTTP {$response->status()} after retries."
            );
        }

        return $response;
    }
}
