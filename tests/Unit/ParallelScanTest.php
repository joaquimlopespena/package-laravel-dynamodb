<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use Mockery;

class ParallelScanTest extends TestCase
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

    public function test_count_items_parallel_returns_integer()
    {
        // Mock 4 segments, each returning count of 25
        $this->mockClient->shouldReceive('scan')
            ->times(4)
            ->andReturn(new Result([
                'Count' => 25,
                'ScannedCount' => 25,
            ]));

        $count = $this->connection->countItemsParallel('test_table', 4);
        
        $this->assertEquals(100, $count);
    }

    public function test_count_items_parallel_with_default_segments()
    {
        // Default is 4 segments
        $this->mockClient->shouldReceive('scan')
            ->times(4)
            ->andReturn(new Result([
                'Count' => 10,
            ]));

        $count = $this->connection->countItemsParallel('test_table');
        
        $this->assertEquals(40, $count);
    }

    public function test_count_items_parallel_with_custom_segments()
    {
        // Test with 8 segments
        $this->mockClient->shouldReceive('scan')
            ->times(8)
            ->andReturn(new Result([
                'Count' => 15,
            ]));

        $count = $this->connection->countItemsParallel('test_table', 8);
        
        $this->assertEquals(120, $count);
    }

    public function test_count_items_parallel_validates_segment_range()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Segments must be between 1 and 100');
        
        $this->connection->countItemsParallel('test_table', 0);
    }

    public function test_count_items_parallel_validates_max_segments()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Segments must be between 1 and 100');
        
        $this->connection->countItemsParallel('test_table', 101);
    }

    public function test_count_items_parallel_handles_pagination()
    {
        // First call returns LastEvaluatedKey, second call returns final count
        $this->mockClient->shouldReceive('scan')
            ->times(8) // 4 segments * 2 calls each
            ->andReturnUsing(function($args) {
                static $callCount = 0;
                $callCount++;
                
                // Alternate between having LastEvaluatedKey and not having it
                if ($callCount % 2 === 1) {
                    return new Result([
                        'Count' => 50,
                        'LastEvaluatedKey' => ['id' => ['S' => 'key']],
                    ]);
                } else {
                    return new Result([
                        'Count' => 50,
                    ]);
                }
            });

        $count = $this->connection->countItemsParallel('test_table', 4);
        
        $this->assertEquals(400, $count); // 4 segments * 100 items each
    }

    public function test_count_items_parallel_with_empty_table()
    {
        $this->mockClient->shouldReceive('scan')
            ->times(4)
            ->andReturn(new Result([
                'Count' => 0,
            ]));

        $count = $this->connection->countItemsParallel('test_table', 4);
        
        $this->assertEquals(0, $count);
    }

    public function test_count_items_parallel_continues_on_segment_error()
    {
        // First segment throws exception, others succeed
        $callCount = 0;
        $this->mockClient->shouldReceive('scan')
            ->times(4)
            ->andReturnUsing(function($args) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \Exception('Segment failed');
                }
                return new Result([
                    'Count' => 30,
                ]);
            });

        $count = $this->connection->countItemsParallel('test_table', 4);
        
        // Should have counted 3 segments * 30 = 90
        $this->assertEquals(90, $count);
    }

    public function test_count_items_parallel_with_filter_expression()
    {
        $this->mockClient->shouldReceive('scan')
            ->times(4)
            ->withArgs(function($args) {
                return isset($args['FilterExpression']) 
                    && isset($args['ExpressionAttributeValues']);
            })
            ->andReturn(new Result([
                'Count' => 20,
            ]));

        $filterExpression = [
            'FilterExpression' => 'attribute_exists(#status)',
            'ExpressionAttributeNames' => ['#status' => 'status'],
            'ExpressionAttributeValues' => [':val' => 'active'],
        ];

        $count = $this->connection->countItemsParallel('test_table', 4, $filterExpression);
        
        $this->assertEquals(80, $count);
    }

    public function test_count_items_single_segment()
    {
        // Test with single segment (essentially sequential)
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Count' => 100,
            ]));

        $count = $this->connection->countItemsParallel('test_table', 1);
        
        $this->assertEquals(100, $count);
    }

    public function test_count_items_sequential_fallback()
    {
        // Test regular countItems method (non-parallel)
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Count' => 50,
            ]));

        $count = $this->connection->countItems('test_table');
        
        $this->assertEquals(50, $count);
    }
}
