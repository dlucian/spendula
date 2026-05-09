<?php

namespace App\Providers;

use App\Services\Counterparty\Resolver;
use App\Services\Counterparty\Rules\RuleEngine;
use App\Services\Counterparty\Rules\RuleLoader;
use App\Services\EnableBanking\Client as EnableBankingClient;
use App\Services\EnableBanking\Jwt as EnableBankingJwt;
use App\Services\ExchangeRates\FrankfurterClient;
use App\Services\ExchangeRates\RateProvider;
use App\Services\Ynab\Client as YnabClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            RuleLoader::class,
            fn () => new RuleLoader(base_path('config/counterparty-rules-enabled')),
        );

        // GH #42 — wire the configurable ATM-cash label into the resolver.
        // The default 'ATM Cash' lives both here and in the constructor's
        // default value; the env var SPENDULA_ATM_CASH_LABEL overrides at
        // boot.
        $this->app->singleton(
            Resolver::class,
            fn (Application $app) => new Resolver(
                $app->make(RuleLoader::class),
                $app->make(RuleEngine::class),
                (string) config('spendula.resolver.atm_cash_label', 'ATM Cash'),
            ),
        );

        $this->app->singleton(EnableBankingJwt::class, fn () => EnableBankingJwt::fromConfig());

        $this->app->singleton(
            EnableBankingClient::class,
            fn (Application $app) => new EnableBankingClient(
                $app->make(EnableBankingJwt::class),
                (string) config('spendula.enable_banking.base_url'),
            ),
        );

        $this->app->singleton(YnabClient::class, fn () => YnabClient::fromConfig());

        $this->app->singleton(RateProvider::class, function (Application $app): RateProvider {
            $provider = (string) config('spendula.exchange_rates.provider');
            $baseUrl = (string) config('spendula.exchange_rates.base_url');

            return match ($provider) {
                'frankfurter' => new FrankfurterClient($baseUrl),
                default => throw new RuntimeException(
                    "Unknown exchange rate provider [{$provider}]. Set SPENDULA_EXCHANGE_RATE_PROVIDER to a supported value (currently: frankfurter)."
                ),
            };
        });
    }

    public function boot(): void
    {
        //
    }
}
