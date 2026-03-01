<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Connector;

use Aws\DynamoDb\DynamoDbClient;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;

/**
 * DynamoDB Connector Class.
 * 
 * Responsável por estabelecer e configurar conexões com o DynamoDB,
 * tanto para AWS DynamoDB em produção quanto para DynamoDB Local em desenvolvimento.
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Connector
 * @since 1.0.0
 */
class DynamoDbConnector
{
    /**
     * Estabelecer conexão com DynamoDB.
     * 
     * Cria uma instância do DynamoDbClient com as configurações fornecidas
     * e retorna uma nova DynamoDbConnection pronta para uso.
     *
     * @param array $config Configurações da conexão incluindo:
     *                      - region: Região AWS (ex: 'us-east-1')
     *                      - key: AWS Access Key ID (opcional se usar IAM roles)
     *                      - secret: AWS Secret Access Key (opcional se usar IAM roles)
     *                      - endpoint: Endpoint customizado (ex: 'http://localhost:8000' para DynamoDB Local)
     *                      - table: Nome da tabela DynamoDB padrão
     * 
     * @return DynamoDbConnection Instância configurada da conexão DynamoDB
     * 
     * @example
     * $connector = new DynamoDbConnector();
     * $connection = $connector->connect([
     *     'region' => 'us-east-1',
     *     'key' => 'AKIAIOSFODNN7EXAMPLE',
     *     'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
     *     'table' => 'users'
     * ]);
     * 
     * @since 1.0.0
     */
    public function connect(array $config): DynamoDbConnection
    {
        $client = $this->createDynamoDbClient($config);

        return new DynamoDbConnection($client, $config);
    }

    /**
     * Criar instância do DynamoDbClient.
     * 
     * Configura o cliente AWS SDK para DynamoDB com credenciais e região apropriadas.
     * Suporta tanto AWS DynamoDB (produção) quanto DynamoDB Local (desenvolvimento).
     * Para DynamoDB Local, desabilita verificação SSL e usa credenciais de exemplo.
     *
     * @param array $config Configurações incluindo region, key, secret, endpoint
     * 
     * @return DynamoDbClient Cliente AWS SDK configurado
     * 
     * @example
     * // Para AWS DynamoDB (produção)
     * $client = $connector->createDynamoDbClient([
     *     'region' => 'us-east-1',
     *     'key' => 'YOUR_ACCESS_KEY',
     *     'secret' => 'YOUR_SECRET_KEY'
     * ]);
     * 
     * // Para DynamoDB Local (desenvolvimento)
     * $client = $connector->createDynamoDbClient([
     *     'region' => 'us-east-1',
     *     'endpoint' => 'http://localhost:8000'
     * ]);
     * 
     * @since 1.0.0
     */
    protected function createDynamoDbClient(array $config): DynamoDbClient
    {
        $clientConfig = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
        ];

        // Endpoint para DynamoDB Local (se fornecido)
        if (!empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];

            // DynamoDB Local: sempre usar credenciais válidas
            // Alguns DynamoDB Local exigem credenciais no formato AWS válido
            $clientConfig['credentials'] = [
                'key' => $config['key'] ?: 'AKIAIOSFODNN7EXAMPLE',
                'secret' => $config['secret'] ?: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ];

            // Desabilitar SSL verification para DynamoDB Local (HTTP)
            $clientConfig['http'] = [
                'verify' => false,
            ];
        } elseif (!empty($config['key']) && !empty($config['secret'])) {
            // Credenciais AWS (produção)
            $clientConfig['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        return new DynamoDbClient($clientConfig);
    }
}

