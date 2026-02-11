<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder as DynamoDbBuilder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Result;
use Mockery;

class PaginationTest extends TestCase
{
    protected DynamoDbConnection $connection;
    protected DynamoDbBuilder $builder;
    protected $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockClient = Mockery::mock(DynamoDbClient::class);
        $this->connection = new DynamoDbConnection($this->mockClient, [
            'region' => 'us-east-1',
            'table' => 'test_table',
        ]);
        
        $this->builder = new DynamoDbBuilder($this->connection);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_simple_paginate_returns_paginator()
    {
        // Mock DynamoDB scan result
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Items' => [],
                'Count' => 0,
            ]));

        $builder = $this->builder->from('test_table');
        $paginator = $builder->simplePaginate(15);
        
        $this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $paginator);
    }

    public function test_simple_paginate_respects_per_page_parameter()
    {
        // Mock DynamoDB scan result with items
        $items = array_map(function($i) {
            return ['id' => ['S' => "item-{$i}"]];
        }, range(1, 16));
        
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Items' => array_slice($items, 0, 11), // 10 + 1 to check for next page
                'Count' => 11,
            ]));

        $builder = $this->builder->from('test_table');
        $paginator = $builder->simplePaginate(10);
        
        $this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $paginator);
        $this->assertEquals(10, $paginator->perPage());
    }

    public function test_simple_paginate_can_handle_cursor()
    {
        // Mock DynamoDB scan with ExclusiveStartKey
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->withArgs(function($args) {
                return isset($args['ExclusiveStartKey']);
            })
            ->andReturn(new Result([
                'Items' => [],
                'Count' => 0,
            ]));

        // Create a cursor (base64 encoded JSON)
        $lastKey = ['id' => 'test-123'];
        $cursor = base64_encode(json_encode($lastKey));
        
        $builder = $this->builder->from('test_table');
        $paginator = $builder->simplePaginate(15, ['*'], 'cursor', $cursor);
        
        $this->assertInstanceOf(\Illuminate\Pagination\Paginator::class, $paginator);
    }

    public function test_simple_paginate_detects_has_more_pages()
    {
        // Return perPage + 1 items to indicate more pages exist
        $items = array_map(function($i) {
            return ['id' => ['S' => "item-{$i}"], 'name' => ['S' => "Name {$i}"]];
        }, range(1, 11)); // 10 + 1
        
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Items' => $items,
                'Count' => 11,
            ]));

        $builder = $this->builder->from('test_table');
        $paginator = $builder->simplePaginate(10);
        
        $this->assertTrue($paginator->hasMorePages());
    }

    public function test_simple_paginate_detects_no_more_pages()
    {
        // Return fewer items than perPage to indicate no more pages
        $items = array_map(function($i) {
            return ['id' => ['S' => "item-{$i}"]];
        }, range(1, 5));
        
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Items' => $items,
                'Count' => 5,
            ]));

        $builder = $this->builder->from('test_table');
        $paginator = $builder->simplePaginate(10);
        
        $this->assertFalse($paginator->hasMorePages());
    }

    public function test_cursor_encoding_and_decoding()
    {
        $lastEvaluatedKey = [
            'id' => 'test-id-123',
            'sort_key' => 'sort-value',
        ];
        
        // Encode
        $cursor = base64_encode(json_encode($lastEvaluatedKey));
        
        // Decode
        $decoded = json_decode(base64_decode($cursor), true);
        
        $this->assertEquals($lastEvaluatedKey, $decoded);
    }

    public function test_simple_paginate_handles_empty_results()
    {
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Items' => [],
                'Count' => 0,
            ]));

        $builder = $this->builder->from('test_table');
        $paginator = $builder->simplePaginate(15);
        
        $this->assertCount(0, $paginator->items());
        $this->assertFalse($paginator->hasMorePages());
    }

    public function test_simple_paginate_handles_last_evaluated_key()
    {
        // Mock result with LastEvaluatedKey
        $items = array_map(function($i) {
            return ['id' => ['S' => "item-{$i}"]];
        }, range(1, 11));
        
        $this->mockClient->shouldReceive('scan')
            ->once()
            ->andReturn(new Result([
                'Items' => $items,
                'Count' => 11,
                'LastEvaluatedKey' => ['id' => ['S' => 'last-item']],
            ]));

        $builder = $this->builder->from('test_table');
        $paginator = $builder->simplePaginate(10);
        
        $this->assertTrue($paginator->hasMorePages());
    }
}
