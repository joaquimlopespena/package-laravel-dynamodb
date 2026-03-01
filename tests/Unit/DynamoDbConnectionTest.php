<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Grammar as DynamoDbGrammar;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Processor as DynamoDbProcessor;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Mockery;

class DynamoDbConnectionTest extends TestCase
{
    protected DynamoDbClient $mockClient;
    protected DynamoDbConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockClient = Mockery::mock(DynamoDbClient::class);
        
        $this->connection = new DynamoDbConnection($this->mockClient, [
            'region' => 'us-east-1',
            'table' => 'test_table',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_can_create_connection_instance()
    {
        $this->assertInstanceOf(DynamoDbConnection::class, $this->connection);
    }

    public function test_connection_has_dynamodb_client()
    {
        $client = $this->connection->getDynamoDbClient();
        
        $this->assertInstanceOf(DynamoDbClient::class, $client);
        $this->assertSame($this->mockClient, $client);
    }

    public function test_connection_has_marshaler()
    {
        $marshaler = $this->connection->getMarshaler();
        
        $this->assertInstanceOf(Marshaler::class, $marshaler);
    }

    public function test_connection_uses_dynamodb_grammar()
    {
        $grammar = $this->connection->getQueryGrammar();
        
        $this->assertInstanceOf(DynamoDbGrammar::class, $grammar);
    }

    public function test_connection_uses_dynamodb_processor()
    {
        $processor = $this->connection->getPostProcessor();
        
        $this->assertInstanceOf(DynamoDbProcessor::class, $processor);
    }

    public function test_connection_can_get_table_name()
    {
        $tableName = $this->connection->getTableName();
        
        $this->assertEquals('test_table', $tableName);
    }

    public function test_connection_throws_exception_for_sql_query()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DynamoDB does not support SQL queries');
        
        $this->connection->select('SELECT * FROM test');
    }

    public function test_connection_can_create_query_builder()
    {
        $query = $this->connection->query();
        
        $this->assertInstanceOf(\Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder::class, $query);
    }

    public function test_connection_uses_database_config_as_fallback()
    {
        $connection = new DynamoDbConnection($this->mockClient, [
            'database' => 'fallback_table',
        ]);
        
        $this->assertEquals('fallback_table', $connection->getTableName());
    }

    public function test_connection_prefers_table_over_database_config()
    {
        $connection = new DynamoDbConnection($this->mockClient, [
            'table' => 'primary_table',
            'database' => 'fallback_table',
        ]);
        
        $this->assertEquals('primary_table', $connection->getTableName());
    }

    public function test_connection_can_clear_metadata_cache()
    {
        $this->connection->clearMetadataCache();
        
        // If it doesn't throw, it's working
        $this->assertTrue(true);
    }

    public function test_connection_can_clear_specific_table_metadata_cache()
    {
        $this->connection->clearMetadataCache('specific_table');
        
        // If it doesn't throw, it's working
        $this->assertTrue(true);
    }

    public function test_connection_validates_region_from_config()
    {
        $connection = new DynamoDbConnection($this->mockClient, [
            'region' => 'eu-west-1',
            'table' => 'test_table',
        ]);
        
        $this->assertEquals('eu-west-1', $connection->getConfig('region'));
    }

    public function test_insert_throws_exception_for_invalid_format()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid insert query format');
        
        $this->connection->insert('INSERT INTO test VALUES (1)');
    }

    public function test_update_throws_exception_for_invalid_format()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid update query format');
        
        $this->connection->update('UPDATE test SET foo = bar');
    }

    public function test_delete_throws_exception_for_invalid_format()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid delete query format');
        
        $this->connection->delete('DELETE FROM test');
    }
}
