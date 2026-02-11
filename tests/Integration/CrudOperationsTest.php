<?php

namespace Joaquim\LaravelDynamoDb\Tests\Integration;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use Mockery;

class CrudOperationsTest extends TestCase
{
    protected DynamoDbConnection $connection;
    protected $mockClient;

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

    public function test_can_insert_item()
    {
        $this->mockClient->shouldReceive('putItem')
            ->once()
            ->withArgs(function($args) {
                return $args['TableName'] === 'test_table'
                    && isset($args['Item']['id'])
                    && isset($args['Item']['name']);
            })
            ->andReturn(new Result([]));

        $query = [
            'params' => [
                'TableName' => 'test_table',
                'Item' => [
                    'id' => 'test-123',
                    'name' => 'Test Item',
                    'email' => 'test@example.com',
                ],
            ],
        ];

        $result = $this->connection->insert($query);
        
        $this->assertTrue($result);
    }

    public function test_can_get_item_by_key()
    {
        $this->mockClient->shouldReceive('getItem')
            ->once()
            ->andReturn(new Result([
                'Item' => [
                    'id' => ['S' => 'test-123'],
                    'name' => ['S' => 'Test Item'],
                    'email' => ['S' => 'test@example.com'],
                ],
            ]));

        $query = [
            'operation' => 'GetItem',
            'params' => [
                'TableName' => 'test_table',
                'Key' => ['id' => 'test-123'],
            ],
        ];

        $results = $this->connection->select($query);
        
        $this->assertCount(1, $results);
        $this->assertEquals('test-123', $results[0]->id);
        $this->assertEquals('Test Item', $results[0]->name);
    }

    public function test_can_update_item()
    {
        $this->mockClient->shouldReceive('updateItem')
            ->once()
            ->withArgs(function($args) {
                return $args['TableName'] === 'test_table'
                    && isset($args['Key'])
                    && isset($args['UpdateExpression']);
            })
            ->andReturn(new Result([]));

        $query = [
            'params' => [
                'TableName' => 'test_table',
                'Key' => ['id' => 'test-123'],
                'UpdateExpression' => 'SET #name = :name',
                'ExpressionAttributeNames' => ['#name' => 'name'],
                'ExpressionAttributeValues' => [':name' => 'Updated Name'],
            ],
        ];

        $result = $this->connection->update($query);
        
        $this->assertEquals(1, $result);
    }

    public function test_can_delete_item()
    {
        $this->mockClient->shouldReceive('deleteItem')
            ->once()
            ->withArgs(function($args) {
                return $args['TableName'] === 'test_table'
                    && isset($args['Key']);
            })
            ->andReturn(new Result([]));

        $query = [
            'params' => [
                'TableName' => 'test_table',
                'Key' => ['id' => 'test-123'],
            ],
        ];

        $result = $this->connection->delete($query);
        
        $this->assertEquals(1, $result);
    }

    public function test_can_scan_table()
    {
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Items' => [
                    [
                        'id' => ['S' => 'item-1'],
                        'name' => ['S' => 'First Item'],
                    ],
                    [
                        'id' => ['S' => 'item-2'],
                        'name' => ['S' => 'Second Item'],
                    ],
                ],
                'Count' => 2,
            ]));

        $query = [
            'operation' => 'Scan',
            'params' => [
                'TableName' => 'test_table',
            ],
        ];

        $results = $this->connection->select($query);
        
        $this->assertCount(2, $results);
        $this->assertEquals('item-1', $results[0]->id);
        $this->assertEquals('item-2', $results[1]->id);
    }

    public function test_can_query_with_key_condition()
    {
        $this->mockClient->shouldReceive('query')
            ->once()
            ->withArgs(function($args) {
                return isset($args['KeyConditionExpression'])
                    && isset($args['ExpressionAttributeValues']);
            })
            ->andReturn(new Result([
                'Items' => [
                    [
                        'id' => ['S' => 'test-123'],
                        'name' => ['S' => 'Test Item'],
                    ],
                ],
                'Count' => 1,
            ]));

        $query = [
            'operation' => 'Query',
            'params' => [
                'TableName' => 'test_table',
                'KeyConditionExpression' => 'id = :id',
                'ExpressionAttributeValues' => [':id' => 'test-123'],
            ],
        ];

        $results = $this->connection->select($query);
        
        $this->assertCount(1, $results);
        $this->assertEquals('test-123', $results[0]->id);
    }

    public function test_can_batch_get_items()
    {
        $this->mockClient->shouldReceive('batchGetItem')
            ->once()
            ->andReturn(new Result([
                'Responses' => [
                    'test_table' => [
                        [
                            'id' => ['S' => 'item-1'],
                            'name' => ['S' => 'First Item'],
                        ],
                        [
                            'id' => ['S' => 'item-2'],
                            'name' => ['S' => 'Second Item'],
                        ],
                    ],
                ],
            ]));

        $query = [
            'operation' => 'BatchGetItem',
            'params' => [
                'TableName' => 'test_table',
                'Keys' => [
                    ['id' => 'item-1'],
                    ['id' => 'item-2'],
                ],
            ],
        ];

        $results = $this->connection->select($query);
        
        $this->assertCount(2, $results);
    }

    public function test_get_item_returns_empty_when_not_found()
    {
        $this->mockClient->shouldReceive('getItem')
            ->once()
            ->andReturn(new Result([])); // No Item key

        $query = [
            'operation' => 'GetItem',
            'params' => [
                'TableName' => 'test_table',
                'Key' => ['id' => 'nonexistent'],
            ],
        ];

        $results = $this->connection->select($query);
        
        $this->assertEmpty($results);
    }

    public function test_query_with_filter_expression()
    {
        $this->mockClient->shouldReceive('query')
            ->once()
            ->withArgs(function($args) {
                return isset($args['FilterExpression'])
                    && isset($args['KeyConditionExpression']);
            })
            ->andReturn(new Result([
                'Items' => [
                    [
                        'id' => ['S' => 'active-1'],
                        'status' => ['S' => 'active'],
                    ],
                ],
                'Count' => 1,
            ]));

        $query = [
            'operation' => 'Query',
            'params' => [
                'TableName' => 'test_table',
                'KeyConditionExpression' => 'user_id = :user_id',
                'FilterExpression' => '#status = :status',
                'ExpressionAttributeNames' => ['#status' => 'status'],
                'ExpressionAttributeValues' => [
                    ':user_id' => 'user-123',
                    ':status' => 'active',
                ],
            ],
        ];

        $results = $this->connection->select($query);
        
        $this->assertCount(1, $results);
        $this->assertEquals('active', $results[0]->status);
    }

    public function test_scan_with_projection_expression()
    {
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->withArgs(function($args) {
                return isset($args['ProjectionExpression']);
            })
            ->andReturn(new Result([
                'Items' => [
                    [
                        'id' => ['S' => 'item-1'],
                        'name' => ['S' => 'Item Name'],
                    ],
                ],
                'Count' => 1,
            ]));

        $query = [
            'operation' => 'Scan',
            'params' => [
                'TableName' => 'test_table',
                'ProjectionExpression' => 'id, #name',
                'ExpressionAttributeNames' => ['#name' => 'name'],
            ],
        ];

        $results = $this->connection->select($query);
        
        $this->assertCount(1, $results);
        $this->assertEquals('item-1', $results[0]->id);
        $this->assertEquals('Item Name', $results[0]->name);
    }
}
