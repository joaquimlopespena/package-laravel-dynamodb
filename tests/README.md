# Testing Documentation

This document explains how to run tests for the Laravel DynamoDB package.

## Overview

The test suite uses:
- **PHPUnit**: Testing framework
- **Orchestra Testbench**: Laravel package testing bridge
- **Mockery**: Mocking framework for AWS SDK

## Running Tests

### Run All Tests

```bash
./vendor/bin/phpunit
```

or using Composer:

```bash
composer test
```

### Run Specific Test Suite

Run only unit tests:
```bash
./vendor/bin/phpunit --testsuite "DynamoDB Package Test Suite"
```

### Run Specific Test File

```bash
./vendor/bin/phpunit tests/Unit/DynamoDbConnectionTest.php
```

### Run Specific Test Method

```bash
./vendor/bin/phpunit --filter test_can_create_connection_instance
```

## Test Structure

```
tests/
├── TestCase.php              # Base test case for all tests
├── Unit/                     # Unit tests
│   ├── DynamoDbConnectorTest.php
│   ├── DynamoDbConnectionTest.php
│   ├── QueryBuilderTest.php
│   ├── EloquentModelTest.php
│   ├── PaginationTest.php
│   ├── ParallelScanTest.php
│   ├── ErrorHandlingTest.php
│   ├── GrammarTest.php
│   ├── ProcessorTest.php
│   └── ServiceProviderTest.php
├── Integration/              # Integration tests
│   └── CrudOperationsTest.php
└── Feature/                  # Feature tests (future)
```

## Test Coverage

The test suite covers:

### 1. Connection Tests
- ✅ Creating DynamoDB connection instances
- ✅ Configuration with credentials
- ✅ Region validation
- ✅ DynamoDB Local support
- ✅ Marshaler functionality
- ✅ Query grammar and processor

### 2. Connector Tests
- ✅ Successful connection establishment
- ✅ DynamoDbClient creation
- ✅ Endpoint parameterization
- ✅ Default credential handling

### 3. Query Builder Tests
- ✅ Basic query construction
- ✅ WHERE clauses
- ✅ SELECT specific columns
- ✅ ORDER BY
- ✅ LIMIT and OFFSET
- ✅ Method chaining

### 4. Pagination Tests
- ✅ simplePaginate() functionality
- ✅ Cursor-based pagination
- ✅ LastEvaluatedKey handling
- ✅ Multiple pages detection
- ✅ Cursor encoding/decoding

### 5. Parallel Scan Tests
- ✅ Scan with segments
- ✅ Sequential processing
- ✅ Count operations with parallel segments
- ✅ Filter expressions
- ✅ Error handling per segment

### 6. Eloquent Model Tests
- ✅ Model instance creation
- ✅ Timestamps
- ✅ Primary key configuration
- ✅ Fillable attributes
- ✅ Array/JSON conversion

### 7. Integration Tests (CRUD)
- ✅ Create operations (PutItem)
- ✅ Read operations (GetItem, Scan, Query)
- ✅ Update operations (UpdateItem)
- ✅ Delete operations (DeleteItem)
- ✅ Batch operations
- ✅ Filter expressions
- ✅ Projection expressions

### 8. Error Handling Tests
- ✅ Invalid operation exceptions
- ✅ Empty item validation
- ✅ SQL string rejection
- ✅ DynamoDB service exceptions
- ✅ Validation errors
- ✅ Conditional check failures
- ✅ Network timeouts

### 9. Service Provider Tests
- ✅ Provider registration
- ✅ Configuration setup
- ✅ Connection resolver

## Mocking Strategy

Tests use Mockery to mock AWS DynamoDB SDK calls, avoiding the need for:
- Real AWS credentials
- Running DynamoDB Local
- Network calls

Example:
```php
$mockClient = Mockery::mock(DynamoDbClient::class);
$mockClient->shouldReceive('getItem')
    ->once()
    ->andReturn(new Result([
        'Item' => ['id' => ['S' => 'test-123']]
    ]));
```

## Configuration

Test configuration is in `phpunit.xml`:
- Environment variables for DynamoDB Local
- Test database connection settings
- Coverage settings

## Writing New Tests

When adding new tests:

1. Extend `Joaquim\LaravelDynamoDb\Tests\TestCase`
2. Use Mockery for AWS SDK mocking
3. Clean up mocks in `tearDown()`
4. Follow existing naming conventions
5. Group related tests in the same file

Example:
```php
<?php

namespace Joaquim\LaravelDynamoDb\Tests\Unit;

use Joaquim\LaravelDynamoDb\Tests\TestCase;
use Mockery;

class MyNewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup code
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_something()
    {
        // Test code
        $this->assertTrue(true);
    }
}
```

## Continuous Integration

Tests run automatically on:
- Pull requests
- Commits to main branch
- Release tags

CI configuration should:
1. Install dependencies: `composer install`
2. Run tests: `./vendor/bin/phpunit`
3. Generate coverage reports (optional)

## Coverage Goals

Target: **70-80% code coverage**

Current coverage areas:
- Core functionality: ~90%
- Edge cases: ~70%
- Error handling: ~75%

## Troubleshooting

### Tests fail with "Undefined array key"
- Check configuration has all required keys
- Verify mock setup is complete

### Memory issues
- Increase PHP memory limit: `php -d memory_limit=512M vendor/bin/phpunit`

### Timeout issues
- Adjust timeout in phpunit.xml
- Check for infinite loops in code

## Future Improvements

- [ ] Add mutation testing (Infection PHP)
- [ ] Add performance benchmarks
- [ ] Integration tests with real DynamoDB Local
- [ ] Add Pest as alternative test runner
- [ ] Increase coverage to 85%+
