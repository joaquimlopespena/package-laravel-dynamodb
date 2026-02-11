<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model as DynamoDbModel;

class TestModel extends DynamoDbModel
{
    protected $connection = 'dynamodb';
    protected $table = 'test_models';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;
    
    protected $fillable = ['id', 'name', 'email', 'status'];
}

class EloquentModelTest extends TestCase
{
    public function test_can_create_model_instance()
    {
        $model = new TestModel();
        
        $this->assertInstanceOf(DynamoDbModel::class, $model);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Model::class, $model);
    }

    public function test_model_has_correct_connection()
    {
        $model = new TestModel();
        
        $this->assertEquals('dynamodb', $model->getConnectionName());
    }

    public function test_model_has_correct_table()
    {
        $model = new TestModel();
        
        $this->assertEquals('test_models', $model->getTable());
    }

    public function test_model_has_correct_primary_key()
    {
        $model = new TestModel();
        
        $this->assertEquals('id', $model->getKeyName());
    }

    public function test_model_has_correct_key_type()
    {
        $model = new TestModel();
        
        $this->assertEquals('string', $model->getKeyType());
    }

    public function test_model_is_not_incrementing()
    {
        $model = new TestModel();
        
        $this->assertFalse($model->getIncrementing());
    }

    public function test_model_uses_timestamps()
    {
        $model = new TestModel();
        
        $this->assertTrue($model->usesTimestamps());
    }

    public function test_model_can_set_attributes()
    {
        $model = new TestModel();
        $model->id = 'test-123';
        $model->name = 'Test User';
        $model->email = 'test@example.com';
        
        $this->assertEquals('test-123', $model->id);
        $this->assertEquals('Test User', $model->name);
        $this->assertEquals('test@example.com', $model->email);
    }

    public function test_model_can_fill_attributes()
    {
        $model = new TestModel([
            'id' => 'test-456',
            'name' => 'Another User',
            'email' => 'another@example.com',
            'status' => 'active',
        ]);
        
        $this->assertEquals('test-456', $model->id);
        $this->assertEquals('Another User', $model->name);
        $this->assertEquals('another@example.com', $model->email);
        $this->assertEquals('active', $model->status);
    }

    public function test_model_respects_fillable()
    {
        $model = new TestModel();
        
        $this->assertContains('id', $model->getFillable());
        $this->assertContains('name', $model->getFillable());
        $this->assertContains('email', $model->getFillable());
        $this->assertContains('status', $model->getFillable());
    }

    public function test_model_can_convert_to_array()
    {
        $model = new TestModel([
            'id' => 'test-789',
            'name' => 'Array User',
            'email' => 'array@example.com',
        ]);
        
        $array = $model->toArray();
        
        $this->assertIsArray($array);
        $this->assertEquals('test-789', $array['id']);
        $this->assertEquals('Array User', $array['name']);
        $this->assertEquals('array@example.com', $array['email']);
    }

    public function test_model_can_convert_to_json()
    {
        $model = new TestModel([
            'id' => 'test-json',
            'name' => 'JSON User',
        ]);
        
        $json = $model->toJson();
        
        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('test-json', $decoded['id']);
        $this->assertEquals('JSON User', $decoded['name']);
    }

    public function test_model_has_attributes_property()
    {
        $model = new TestModel(['id' => 'test-attr']);
        
        $this->assertArrayHasKey('id', $model->getAttributes());
    }

    public function test_model_can_check_if_attribute_exists()
    {
        $model = new TestModel(['id' => 'test', 'name' => 'Test']);
        
        $this->assertTrue(isset($model->id));
        $this->assertTrue(isset($model->name));
        $this->assertFalse(isset($model->nonexistent));
    }
}
