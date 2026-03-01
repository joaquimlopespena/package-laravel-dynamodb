<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connector\DynamoDbConnector;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Aws\DynamoDb\DynamoDbClient;

class DynamoDbConnectorTest extends TestCase
{
    protected DynamoDbConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new DynamoDbConnector();
    }

    public function test_can_create_connector_instance()
    {
        $this->assertInstanceOf(DynamoDbConnector::class, $this->connector);
    }

    public function test_connect_returns_dynamodb_connection()
    {
        $config = [
            'region' => 'us-east-1',
            'endpoint' => 'http://localhost:8000',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'table' => 'test_table',
        ];

        $connection = $this->connector->connect($config);

        $this->assertInstanceOf(DynamoDbConnection::class, $connection);
    }

    public function test_connect_creates_dynamodb_client_with_credentials()
    {
        $config = [
            'region' => 'us-west-2',
            'key' => 'test-access-key',
            'secret' => 'test-secret-key',
            'table' => 'test_table',
        ];

        $connection = $this->connector->connect($config);
        $client = $connection->getDynamoDbClient();

        $this->assertInstanceOf(DynamoDbClient::class, $client);
    }

    public function test_connect_supports_dynamodb_local_endpoint()
    {
        $config = [
            'region' => 'us-east-1',
            'endpoint' => 'http://localhost:8000',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'table' => 'test_table',
        ];

        $connection = $this->connector->connect($config);
        $client = $connection->getDynamoDbClient();

        $this->assertInstanceOf(DynamoDbClient::class, $client);
    }

    public function test_connect_uses_default_region_when_not_specified()
    {
        $config = [
            'endpoint' => 'http://localhost:8000',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'table' => 'test_table',
        ];

        $connection = $this->connector->connect($config);

        $this->assertInstanceOf(DynamoDbConnection::class, $connection);
    }

    public function test_connection_has_marshaler()
    {
        $config = [
            'region' => 'us-east-1',
            'endpoint' => 'http://localhost:8000',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'table' => 'test_table',
        ];

        $connection = $this->connector->connect($config);
        $marshaler = $connection->getMarshaler();

        $this->assertInstanceOf(\Aws\DynamoDb\Marshaler::class, $marshaler);
    }

    public function test_connect_without_credentials_uses_defaults_for_local()
    {
        $config = [
            'region' => 'us-east-1',
            'endpoint' => 'http://localhost:8000',
            'key' => '',
            'secret' => '',
            'table' => 'test_table',
        ];

        $connection = $this->connector->connect($config);

        $this->assertInstanceOf(DynamoDbConnection::class, $connection);
    }
}
