<?php

namespace App\Providers;

use App\Services\EnableBanking\Client as EnableBankingClient;
use App\Services\EnableBanking\Jwt as EnableBankingJwt;
use App\Services\Ynab\Client as YnabClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EnableBankingJwt::class, fn () => EnableBankingJwt::fromConfig());

        $this->app->singleton(
            EnableBankingClient::class,
            fn (Application $app) => new EnableBankingClient(
                $app->make(EnableBankingJwt::class),
                (string) config('spendula.enable_banking.base_url'),
            ),
        );

        $this->app->singleton(YnabClient::class, fn () => YnabClient::fromConfig());
    }

    public function boot(): void
    {
        //
    }
}
