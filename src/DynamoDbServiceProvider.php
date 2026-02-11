<?php

namespace Joaquim\LaravelDynamoDb;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Connection;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connector\DynamoDbConnector;

/**
 * DynamoDB Service Provider.
 * 
 * Registra o driver DynamoDB no Laravel e configura conexões.
 * Este service provider é automaticamente carregado pelo Laravel
 * através da configuração em composer.json.
 * 
 * @package Joaquim\LaravelDynamoDb
 * @since 1.0.0
 */
class DynamoDbServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     * 
     * Registra o connector DynamoDB no ConnectionFactory do Laravel
     * e mescla configurações do pacote com o aplicativo.
     * Executado durante a fase de registro do container.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    public function register(): void
    {
        // Registrar connector no ConnectionFactory
        Connection::resolverFor('dynamodb', function ($connection, $database, $prefix, $config) {
            $connector = new DynamoDbConnector();
            return $connector->connect($config);
        });

        // Registrar configuração (usar novo nome, mas manter compatibilidade)
        $configFile = __DIR__ . '/../config/database-dynamodb.php';
        if (file_exists($configFile)) {
            $this->mergeConfigFrom($configFile, 'dynamodb');
        } else {
            // Fallback para nome antigo (compatibilidade retroativa)
            $this->mergeConfigFrom(
                __DIR__ . '/../config/dynamodb.php',
                'dynamodb'
            );
        }
    }

    /**
     * Bootstrap services.
     * 
     * Publica arquivos de configuração e mescla conexões DynamoDB
     * no config/database.php do Laravel. Executado após todos os
     * providers serem registrados.
     * 
     * @return void
     * 
     * @example
     * // Publicar configuração
     * php artisan vendor:publish --tag=dynamodb-config
     * 
     * @since 1.0.0
     */
    public function boot(): void
    {
        // Publicar arquivo de configuração (novo nome)
        $this->publishes([
            __DIR__ . '/../config/database-dynamodb.php' => config_path('database-dynamodb.php'),
        ], 'dynamodb-config');

        // Mesclar conexões do config/dynamodb.php para config/database.php
        $this->mergeDynamoDbConnections();
    }

    /**
     * Mesclar conexões DynamoDB do config para config/database.php
     * 
     * Carrega configurações de database-dynamodb.php (ou dynamodb.php para
     * compatibilidade) e adiciona as conexões DynamoDB em config('database.connections').
     * Cria também um alias 'dynamodb' apontando para a conexão padrão.
     * Suporta tanto database-dynamodb.php (novo) quanto dynamodb.php (legado).
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    protected function mergeDynamoDbConnections(): void
    {
        $dynamoDbConfig = null;

        // Tentar novo nome primeiro
        if (file_exists(config_path('database-dynamodb.php'))) {
            $dynamoDbConfig = require config_path('database-dynamodb.php');
        }
        // Fallback para nome antigo (compatibilidade com código legado)
        elseif (file_exists(config_path('dynamodb.php'))) {
            $dynamoDbConfig = require config_path('dynamodb.php');
        }
        // Se não existe nenhum, usar a config padrão do package
        else {
            $defaultConfig = __DIR__ . '/../config/database-dynamodb.php';
            if (file_exists($defaultConfig)) {
                $dynamoDbConfig = require $defaultConfig;
            } else {
                // Último fallback para nome antigo no package
                $dynamoDbConfig = require __DIR__ . '/../config/dynamodb.php';
            }
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
