<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use FoleyBridgeSolutions\KotapayCashier\CashierServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CashierServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('kotapay.api_key', 'test_api_key');
        $app['config']->set('kotapay.base_url', 'https://api.test.kotapay.com');
    }
}
