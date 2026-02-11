<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Processor;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Aws\DynamoDb\DynamoDbClient;
use Mockery;

class ProcessorTest extends TestCase
{
    protected Processor $processor;
    protected Builder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->processor = new Processor();
        
        $mockClient = Mockery::mock(DynamoDbClient::class);
        $connection = new DynamoDbConnection($mockClient, [
            'region' => 'us-east-1',
            'table' => 'test_table',
        ]);
        
        $this->builder = new Builder($connection);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_can_create_processor_instance()
    {
        $this->assertInstanceOf(Processor::class, $this->processor);
    }

    public function test_processor_processes_select_results()
    {
        $results = [
            (object) ['id' => '1', 'name' => 'Test'],
            (object) ['id' => '2', 'name' => 'Test 2'],
        ];
        
        $processed = $this->processor->processSelect($this->builder, $results);
        
        $this->assertIsArray($processed);
        $this->assertCount(2, $processed);
    }

    public function test_processor_handles_empty_results()
    {
        $results = [];
        
        $processed = $this->processor->processSelect($this->builder, $results);
        
        $this->assertIsArray($processed);
        $this->assertEmpty($processed);
    }
}
