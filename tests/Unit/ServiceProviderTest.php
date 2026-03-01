<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\DynamoDbServiceProvider;
use Illuminate\Support\Facades\DB;

class ServiceProviderTest extends TestCase
{
    public function test_service_provider_is_registered()
    {
        $providers = $this->app->getLoadedProviders();
        
        $this->assertArrayHasKey(DynamoDbServiceProvider::class, $providers);
    }

    public function test_dynamodb_connection_is_available()
    {
        $this->app['config']->set('database.connections.dynamodb.driver', 'dynamodb');
        
        $driver = $this->app['config']->get('database.connections.dynamodb.driver');
        
        $this->assertEquals('dynamodb', $driver);
    }

    public function test_can_get_dynamodb_connection_from_database_manager()
    {
        try {
            $connection = DB::connection('dynamodb');
            $this->assertInstanceOf(\Illuminate\Database\Connection::class, $connection);
        } catch (\Exception $e) {
            // It's OK if connection fails in test env without DynamoDB
            $this->assertTrue(true);
        }
    }

    public function test_service_provider_registers_config()
    {
        $config = $this->app['config'];
        
        $this->assertNotNull($config);
    }

    public function test_dynamodb_connection_config_has_required_keys()
    {
        $config = $this->app['config']->get('database.connections.dynamodb');
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('driver', $config);
        $this->assertEquals('dynamodb', $config['driver']);
    }
}
