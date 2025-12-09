<?php

namespace Joaquim\LaravelDynamoDb;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Connection;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connector\DynamoDbConnector;

class DynamoDbServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Registrar connector no ConnectionFactory
        Connection::resolverFor('dynamodb', function ($connection, $database, $prefix, $config) {
            $connector = new DynamoDbConnector();
            return $connector->connect($config);
        });

        // Registrar configuração
        $this->mergeConfigFrom(
            __DIR__ . '/../config/dynamodb.php',
            'dynamodb'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publicar arquivo de configuração
        $this->publishes([
            __DIR__ . '/../config/dynamodb.php' => config_path('dynamodb.php'),
        ], 'dynamodb-config');

        // Publicar configuração no database.php (opcional)
        $this->publishes([
            __DIR__ . '/../config/dynamodb.php' => config_path('database.php'),
        ], 'dynamodb-config');
    }
}
