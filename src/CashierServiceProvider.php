<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier;

use Illuminate\Support\ServiceProvider;
use FoleyBridgeSolutions\KotapayCashier\Services\ApiClient;
use FoleyBridgeSolutions\KotapayCashier\Services\PaymentService;

class CashierServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/kotapay.php',
            'kotapay'
        );

        $this->app->singleton(ApiClient::class, function ($app) {
            return new ApiClient(
                config('kotapay.base_url'),
                config('kotapay.client_id'),
                config('kotapay.client_secret'),
                config('kotapay.username'),
                config('kotapay.password'),
                config('kotapay.company_id')
            );
        });

        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService(
                $app->make(ApiClient::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/kotapay.php' => config_path('kotapay.php'),
            ], 'kotapay-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'kotapay-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
