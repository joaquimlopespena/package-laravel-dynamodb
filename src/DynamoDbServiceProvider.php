<?php

namespace Joaquim\LaravelDynamoDb;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
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

        // Mesclar conexões do config/dynamodb.php para config/database.php
        $this->mergeDynamoDbConnections();
    }

    /**
     * Mesclar conexões DynamoDB do config/dynamodb.php para config/database.php
     */
    protected function mergeDynamoDbConnections(): void
    {
        // Ler conexões do config/dynamodb.php (se existir)
        if (file_exists(config_path('dynamodb.php'))) {
            $dynamoDbConfig = require config_path('dynamodb.php');
        } else {
            // Se não existe, usar a config padrão do package
            $dynamoDbConfig = require __DIR__ . '/../config/dynamodb.php';
        }

        // Se tem conexões definidas, mesclar com database.php
        if (isset($dynamoDbConfig['connections']) && is_array($dynamoDbConfig['connections'])) {
            foreach ($dynamoDbConfig['connections'] as $name => $connection) {
                // Mesclar conexão (permitir sobrescrever se já existir)
                Config::set("database.connections.{$name}", $connection);
            }

            // Criar conexão 'dynamodb' como alias da conexão padrão
            // Isso mantém compatibilidade com models que usam 'dynamodb' como nome
            $defaultConnection = $dynamoDbConfig['default'] ?? 'local';

            // Se o default não existir, usar 'local' como fallback
            if (!isset($dynamoDbConfig['connections'][$defaultConnection])) {
                $defaultConnection = 'local';
            }

            // Se ainda não existe, usar a primeira conexão disponível
            if (!isset($dynamoDbConfig['connections'][$defaultConnection]) && !empty($dynamoDbConfig['connections'])) {
                $defaultConnection = array_key_first($dynamoDbConfig['connections']);
            }

            if (isset($dynamoDbConfig['connections'][$defaultConnection])) {
                // Copiar a configuração da conexão padrão para 'dynamodb'
                Config::set("database.connections.dynamodb", $dynamoDbConfig['connections'][$defaultConnection]);
            }
        }
    }
}
