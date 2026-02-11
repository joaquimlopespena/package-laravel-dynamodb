<?php

namespace Joaquim\LaravelDynamoDb\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Joaquim\LaravelDynamoDb\DynamoDbServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            DynamoDbServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Setup default database configuration
        $app['config']->set('database.default', 'dynamodb');
        $app['config']->set('database.connections.dynamodb', [
            'driver' => 'dynamodb',
            'region' => env('DYNAMODB_REGION', 'us-east-1'),
            'endpoint' => env('DYNAMODB_ENDPOINT', 'http://localhost:8000'),
            'key' => env('DYNAMODB_ACCESS_KEY_ID', 'AKIAIOSFODNN7EXAMPLE'),
            'secret' => env('DYNAMODB_SECRET_ACCESS_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'),
            'table' => 'test_table',
        ]);
    }
}
