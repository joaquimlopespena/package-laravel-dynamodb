<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder as DynamoDbBuilder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model as DynamoDbModel;
use Aws\DynamoDb\DynamoDbClient;
use Mockery;

class QueryBuilderTest extends TestCase
{
    protected DynamoDbConnection $connection;
    protected DynamoDbBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        
        $mockClient = Mockery::mock(DynamoDbClient::class);
        $this->connection = new DynamoDbConnection($mockClient, [
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

    public function test_can_create_query_builder_instance()
    {
        $this->assertInstanceOf(DynamoDbBuilder::class, $this->builder);
    }

    public function test_query_builder_can_set_table()
    {
        $builder = $this->builder->from('users');
        
        $this->assertEquals('users', $builder->from);
    }

    public function test_query_builder_can_add_where_clause()
    {
        $builder = $this->builder->where('id', '=', '123');
        
        $this->assertNotEmpty($builder->wheres);
        $this->assertEquals('id', $builder->wheres[0]['column']);
        $this->assertEquals('=', $builder->wheres[0]['operator']);
        $this->assertEquals('123', $builder->wheres[0]['value']);
    }

    public function test_query_builder_can_add_multiple_where_clauses()
    {
        $builder = $this->builder
            ->where('status', '=', 'active')
            ->where('age', '>', 18);
        
        $this->assertCount(2, $builder->wheres);
    }

    public function test_query_builder_can_select_specific_columns()
    {
        $builder = $this->builder->select(['id', 'name', 'email']);
        
        $this->assertNotEmpty($builder->columns);
        $this->assertContains('id', $builder->columns);
        $this->assertContains('name', $builder->columns);
        $this->assertContains('email', $builder->columns);
    }

    public function test_query_builder_can_set_limit()
    {
        $builder = $this->builder->limit(10);
        
        $this->assertEquals(10, $builder->limit);
    }

    public function test_query_builder_can_set_offset()
    {
        $builder = $this->builder->offset(20);
        
        $this->assertEquals(20, $builder->offset);
    }

    public function test_query_builder_can_add_order_by()
    {
        $builder = $this->builder->orderBy('created_at', 'desc');
        
        $this->assertNotEmpty($builder->orders);
        $this->assertEquals('created_at', $builder->orders[0]['column']);
        $this->assertEquals('desc', $builder->orders[0]['direction']);
    }

    public function test_query_builder_can_set_model()
    {
        $model = Mockery::mock(DynamoDbModel::class);
        $builder = $this->builder->setModel($model);
        
        $this->assertSame($model, $builder->getModel());
    }

    public function test_query_builder_returns_null_when_no_model_set()
    {
        $this->assertNull($this->builder->getModel());
    }

    public function test_query_builder_can_chain_methods()
    {
        $builder = $this->builder
            ->from('users')
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(5);
        
        $this->assertEquals('users', $builder->from);
        $this->assertCount(2, $builder->columns);
        $this->assertNotEmpty($builder->wheres);
        $this->assertNotEmpty($builder->orders);
        $this->assertEquals(5, $builder->limit);
    }

    public function test_query_builder_can_use_where_shorthand()
    {
        $builder = $this->builder->where('id', '123');
        
        $this->assertNotEmpty($builder->wheres);
        $this->assertEquals('=', $builder->wheres[0]['operator']);
    }

    public function test_query_builder_supports_where_in()
    {
        $builder = $this->builder->whereIn('status', ['active', 'pending']);
        
        $this->assertNotEmpty($builder->wheres);
    }

    public function test_query_builder_supports_or_where()
    {
        $builder = $this->builder
            ->where('status', 'active')
            ->orWhere('status', 'pending');
        
        $this->assertCount(2, $builder->wheres);
    }

    public function test_query_builder_has_connection()
    {
        $this->assertSame($this->connection, $this->builder->getConnection());
    }
}
