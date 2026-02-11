<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Grammar;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Aws\DynamoDb\DynamoDbClient;
use Mockery;

class GrammarTest extends TestCase
{
    protected Grammar $grammar;
    protected DynamoDbConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        
        $mockClient = Mockery::mock(DynamoDbClient::class);
        $this->connection = new DynamoDbConnection($mockClient, [
            'region' => 'us-east-1',
            'table' => 'test_table',
        ]);
        
        $this->grammar = new Grammar($this->connection);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_can_create_grammar_instance()
    {
        $this->assertInstanceOf(Grammar::class, $this->grammar);
    }
}
