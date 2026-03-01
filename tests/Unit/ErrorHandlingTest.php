<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connector\DynamoDbConnector;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use Mockery;

class ErrorHandlingTest extends TestCase
{
    protected $mockClient;
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

    public function test_select_throws_exception_for_unknown_operation()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown operation');

        // Create an invalid compiled query
        $invalidQuery = [
            'operation' => 'InvalidOperation',
            'params' => [],
        ];

        $this->connection->select($invalidQuery);
    }

    public function test_insert_throws_exception_for_empty_item()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot insert empty item');

        $emptyQuery = [
            'params' => [
                'TableName' => 'test_table',
                'Item' => [],
            ],
        ];

        $this->connection->insert($emptyQuery);
    }

    public function test_select_throws_exception_for_sql_string()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DynamoDB does not support SQL queries');

        $this->connection->select('SELECT * FROM test_table');
    }

    public function test_insert_throws_exception_for_sql_string()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid insert query format');

        $this->connection->insert('INSERT INTO test_table VALUES (1, 2, 3)');
    }

    public function test_update_throws_exception_for_sql_string()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid update query format');

        $this->connection->update('UPDATE test_table SET name = "test"');
    }

    public function test_delete_throws_exception_for_sql_string()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid delete query format');

        $this->connection->delete('DELETE FROM test_table WHERE id = 1');
    }

    public function test_handles_dynamodb_service_exception()
    {
        $mockException = Mockery::mock(DynamoDbException::class);
        $mockException->shouldReceive('getMessage')
            ->andReturn('DynamoDB service error');

        $this->mockClient->shouldReceive('getItem')
            ->once()
            ->andThrow($mockException);

        $this->expectException(DynamoDbException::class);

        $query = [
            'operation' => 'GetItem',
            'params' => [
                'TableName' => 'test_table',
                'Key' => ['id' => 'test-123'],
            ],
        ];

        $this->connection->select($query);
    }

    public function test_validates_segment_range_in_parallel_scan()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Segments must be between 1 and 100');

        $this->connection->countItemsParallel('test_table', -1);
    }

    public function test_handles_table_not_found_gracefully()
    {
        $mockException = Mockery::mock(DynamoDbException::class);
        $mockException->shouldReceive('getMessage')
            ->andReturn('ResourceNotFoundException');
        $mockException->shouldReceive('getAwsErrorCode')
            ->andReturn('ResourceNotFoundException');

        $this->mockClient->shouldReceive('describeTable')
            ->once()
            ->andThrow($mockException);

        $this->expectException(DynamoDbException::class);

        $this->connection->getTableMetadata('nonexistent_table');
    }

    public function test_handles_throughput_exceeded_exception()
    {
        $mockException = Mockery::mock(DynamoDbException::class);
        $mockException->shouldReceive('getMessage')
            ->andReturn('ProvisionedThroughputExceededException');
        $mockException->shouldReceive('getAwsErrorCode')
            ->andReturn('ProvisionedThroughputExceededException');

        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andThrow($mockException);

        $this->expectException(DynamoDbException::class);

        $query = [
            'operation' => 'Scan',
            'params' => [
                'TableName' => 'test_table',
            ],
        ];

        $this->connection->select($query);
    }

    public function test_handles_validation_exception()
    {
        $mockException = Mockery::mock(DynamoDbException::class);
        $mockException->shouldReceive('getMessage')
            ->andReturn('ValidationException: Invalid attribute type');
        $mockException->shouldReceive('getAwsErrorCode')
            ->andReturn('ValidationException');

        $this->mockClient->shouldReceive('putItem')
            ->once()
            ->andThrow($mockException);

        $this->expectException(DynamoDbException::class);

        $query = [
            'params' => [
                'TableName' => 'test_table',
                'Item' => ['id' => 'test-123'],
            ],
        ];

        $this->connection->insert($query);
    }

    public function test_handles_conditional_check_failed_exception()
    {
        $mockException = Mockery::mock(DynamoDbException::class);
        $mockException->shouldReceive('getMessage')
            ->andReturn('ConditionalCheckFailedException');
        $mockException->shouldReceive('getAwsErrorCode')
            ->andReturn('ConditionalCheckFailedException');

        $this->mockClient->shouldReceive('updateItem')
            ->once()
            ->andThrow($mockException);

        $this->expectException(DynamoDbException::class);

        $query = [
            'params' => [
                'TableName' => 'test_table',
                'Key' => ['id' => 'test-123'],
                'UpdateExpression' => 'SET #name = :name',
                'ExpressionAttributeNames' => ['#name' => 'name'],
                'ExpressionAttributeValues' => [':name' => 'New Name'],
                'ConditionExpression' => 'attribute_exists(id)',
            ],
        ];

        $this->connection->update($query);
    }

    public function test_handles_network_timeout()
    {
        $mockException = new \Exception('Network timeout');

        $this->mockClient->shouldReceive('query')
            ->once()
            ->andThrow($mockException);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Network timeout');

        $query = [
            'operation' => 'Query',
            'params' => [
                'TableName' => 'test_table',
                'KeyConditionExpression' => 'id = :id',
                'ExpressionAttributeValues' => [':id' => 'test-123'],
            ],
        ];

        $this->connection->select($query);
    }

    public function test_connector_validates_required_config()
    {
        $connector = new DynamoDbConnector();
        
        // Should work with minimal config (region defaults to us-east-1)
        $connection = $connector->connect([
            'table' => 'test_table',
        ]);
        
        $this->assertInstanceOf(DynamoDbConnection::class, $connection);
    }
}
