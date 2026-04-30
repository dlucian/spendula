<?php

namespace Tests\Feature\Services\ExchangeRates;

use App\Models\ExchangeRate;
use App\Services\ExchangeRates\Exceptions\ExchangeRateProviderUnreachableException;
use App\Services\ExchangeRates\Exceptions\ExchangeRateUnavailableException;
use App\Services\ExchangeRates\FrankfurterClient;
use App\Services\ExchangeRates\RateProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class FrankfurterClientTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE_URL = 'https://api.frankfurter.test/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config()->set('spendula.exchange_rates.provider', 'frankfurter');
        config()->set('spendula.exchange_rates.base_url', self::BASE_URL);
    }

    public function test_happy_path_persists_row_and_returns_rate(): void
    {
        Http::fake([
            self::BASE_URL.'/2026-04-24*' => Http::response([
                'amount' => 1.0,
                'base' => 'RON',
                'date' => '2026-04-24',
                'rates' => ['EUR' => 0.19641],
            ], 200),
        ]);

        $rate = $this->client()->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-24'));

        $this->assertSame('RON', $rate->base);
        $this->assertSame('EUR', $rate->quote);
        $this->assertSame('2026-04-24', $rate->rateDate->toDateString());
        $this->assertSame('0.19641', $rate->rate);
        $this->assertSame('frankfurter', $rate->source);

        $this->assertDatabaseHas('exchange_rates', [
            'base_currency' => 'RON',
            'quote_currency' => 'EUR',
            'rate_date' => '2026-04-24',
            'source' => 'frankfurter',
        ]);

        Http::assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), self::BASE_URL.'/2026-04-24')
                && str_contains($request->url(), 'base=RON')
                && str_contains($request->url(), 'symbols=EUR');
        });
        Http::assertSentCount(1);
    }

    public function test_cache_hit_skips_http(): void
    {
        Http::fake([
            self::BASE_URL.'/2026-04-24*' => Http::response([
                'amount' => 1.0,
                'base' => 'RON',
                'date' => '2026-04-24',
                'rates' => ['EUR' => 0.19641],
            ], 200),
        ]);

        $client = $this->client();
        $client->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-24'));
        $second = $client->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-24'));

        // DB stores `decimal(18,8)`, so cache hits return the canonical
        // 8-decimal Postgres representation regardless of provider precision.
        $this->assertSame(0, bccomp($second->rate, '0.19641', 8));
        $this->assertSame('2026-04-24', $second->rateDate->toDateString());
        Http::assertSentCount(1);
        $this->assertSame(1, ExchangeRate::query()->count());
    }

    public function test_weekend_request_resolves_to_friday_then_serves_cache(): void
    {
        Http::fake([
            self::BASE_URL.'/2026-04-25*' => Http::response([
                'amount' => 1.0,
                'base' => 'RON',
                'date' => '2026-04-24',
                'rates' => ['EUR' => 0.19641],
            ], 200),
        ]);

        $client = $this->client();
        $rate = $client->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-25'));

        $this->assertSame('2026-04-24', $rate->rateDate->toDateString());
        $this->assertDatabaseHas('exchange_rates', [
            'base_currency' => 'RON',
            'quote_currency' => 'EUR',
            'rate_date' => '2026-04-24',
        ]);

        // Second call for the same Saturday hits cache via the weekend
        // rollback fallback (rate_date within 2 days of a weekend request).
        $cached = $client->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-25'));
        $this->assertSame('2026-04-24', $cached->rateDate->toDateString());
        Http::assertSentCount(1);
        $this->assertSame(1, ExchangeRate::query()->count());
    }

    public function test_weekday_request_does_not_serve_an_earlier_cached_rate(): void
    {
        Http::fake([
            self::BASE_URL.'/2026-04-24*' => Http::response([
                'amount' => 1.0,
                'base' => 'RON',
                'date' => '2026-04-24',
                'rates' => ['EUR' => 0.19641],
            ], 200),
            self::BASE_URL.'/2026-04-28*' => Http::response([
                'amount' => 1.0,
                'base' => 'RON',
                'date' => '2026-04-28',
                'rates' => ['EUR' => 0.19712],
            ], 200),
        ]);

        $client = $this->client();
        $client->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-24'));

        // Tuesday 2026-04-28 is a business day. Without bounding the
        // fallback, the cached Friday row would be served indefinitely; the
        // client must instead fetch fresh and persist Tuesday's rate.
        $rate = $client->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-28'));

        $this->assertSame('2026-04-28', $rate->rateDate->toDateString());
        $this->assertSame(0, bccomp($rate->rate, '0.19712', 8));
        $this->assertDatabaseHas('exchange_rates', [
            'base_currency' => 'RON',
            'quote_currency' => 'EUR',
            'rate_date' => '2026-04-28',
        ]);
        $this->assertSame(2, ExchangeRate::query()->count());
        Http::assertSentCount(2);
    }

    public function test_5xx_after_retries_raises_unreachable(): void
    {
        Http::fake([
            self::BASE_URL.'/2026-04-24*' => Http::response(['error' => 'boom'], 503),
        ]);

        try {
            $this->client()->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-24'));
            $this->fail('Expected ExchangeRateProviderUnreachableException was not thrown.');
        } catch (ExchangeRateProviderUnreachableException) {
            // expected
        }

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
        $this->assertSame(0, ExchangeRate::query()->count());
    }

    public function test_transport_failure_raises_unreachable(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('refused');
        });

        try {
            $this->client()->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-24'));
            $this->fail('Expected ExchangeRateProviderUnreachableException was not thrown.');
        } catch (ExchangeRateProviderUnreachableException) {
            // expected
        }

        Sleep::assertSleptTimes(2);
        $this->assertSame(0, ExchangeRate::query()->count());
    }

    public function test_malformed_200_raises_unavailable(): void
    {
        Http::fake([
            self::BASE_URL.'/2026-04-24*' => Http::response([
                'amount' => 1.0,
                'base' => 'RON',
                'date' => '2026-04-24',
                'rates' => [],
            ], 200),
        ]);

        $this->expectException(ExchangeRateUnavailableException::class);

        try {
            $this->client()->getRate('RON', 'EUR', CarbonImmutable::parse('2026-04-24'));
        } finally {
            $this->assertSame(0, ExchangeRate::query()->count());
        }
    }

    public function test_unknown_provider_throws_on_resolve(): void
    {
        config()->set('spendula.exchange_rates.provider', 'made-up');

        $this->expectException(RuntimeException::class);
        app(RateProvider::class);
    }

    public function test_known_provider_resolves_to_frankfurter_client(): void
    {
        $this->assertInstanceOf(FrankfurterClient::class, app(RateProvider::class));
    }

    private function client(): FrankfurterClient
    {
        return new FrankfurterClient(self::BASE_URL);
    }
}
