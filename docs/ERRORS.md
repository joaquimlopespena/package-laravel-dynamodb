# Error Handling Guide

This guide explains how to handle errors when using the Laravel DynamoDB package.

## Table of Contents

- [Overview](#overview)
- [Exception Hierarchy](#exception-hierarchy)
- [Common Exceptions](#common-exceptions)
- [Error Handling Examples](#error-handling-examples)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

## Overview

The Laravel DynamoDB package uses custom exceptions to provide detailed error information and make debugging easier. All exceptions extend from `DynamoDbException`, which provides:

- Automatic logging with context
- Custom error codes
- Actionable suggestions for resolving errors
- Stack trace information

## Exception Hierarchy

```
DynamoDbException (Base)
├── ConnectionException
├── ValidationException
├── QueryException
├── OperationException
├── PaginationException
├── BatchOperationException
├── IndexNotFoundException
├── TableNotFoundException
└── TimeoutException
```

## Common Exceptions

### DynamoDbException

Base exception class for all DynamoDB-related errors.

**Properties:**
- `message`: Error message
- `context`: Additional context data (table name, operation, etc.)
- `errorCode`: Custom error code
- `suggestion`: Suggestion for resolving the error

**Methods:**
- `getContext()`: Get exception context
- `getErrorCode()`: Get custom error code
- `getSuggestion()`: Get resolution suggestion
- `getDetailedMessage()`: Get detailed error message with context

### ConnectionException

Thrown when there are connection problems with DynamoDB.

**Common Causes:**
- Invalid AWS credentials
- Network connectivity issues
- DynamoDB service unavailable
- DynamoDB Local not running

**Example:**
```php
try {
    $user = User::find('user-123');
} catch (ConnectionException $e) {
    Log::error('Database unavailable', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
    
    return response()->json([
        'error' => 'Database temporarily unavailable',
    ], 503);
}
```

### ValidationException

Thrown when data validation fails.

**Common Causes:**
- Missing required attributes
- Invalid data types
- Empty insert/update values
- Missing primary key in update/delete

**Example:**
```php
try {
    User::create([
        // Missing required 'id' field
        'name' => 'John Doe',
    ]);
} catch (ValidationException $e) {
    return response()->json([
        'error' => 'Validation failed',
        'message' => $e->getMessage(),
        'suggestion' => $e->getSuggestion(),
    ], 422);
}
```

### QueryException

Thrown when query operations fail.

**Common Causes:**
- Unsupported SQL queries
- Invalid query syntax
- Missing required conditions
- Invalid query format

**Example:**
```php
try {
    // DynamoDB doesn't support SQL
    DB::connection('dynamodb')->select('SELECT * FROM users');
} catch (QueryException $e) {
    Log::warning('Invalid query attempted', [
        'error' => $e->getMessage(),
    ]);
    
    // Use Query Builder instead
    $users = User::all();
}
```

### OperationException

Thrown when CRUD operations fail.

**Common Causes:**
- AWS service errors
- Throughput exceeded
- Request limit exceeded
- Item too large

**Example:**
```php
try {
    User::create([
        'id' => 'user-123',
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]);
} catch (OperationException $e) {
    if (str_contains($e->getMessage(), 'Throughput exceeded')) {
        // Implement retry with exponential backoff
        sleep(1);
        return retry(3, fn() => User::create($data), 100);
    }
    
    throw $e;
}
```

### TableNotFoundException

Thrown when a table doesn't exist.

**Common Causes:**
- Table not created in DynamoDB
- Incorrect table name in model
- Wrong AWS region

**Example:**
```php
try {
    $users = User::all();
} catch (TableNotFoundException $e) {
    Log::error('Table not found', [
        'table' => $e->getContext()['table_name'],
    ]);
    
    return response()->json([
        'error' => 'Resource not configured',
    ], 500);
}
```

### IndexNotFoundException

Thrown when a Global or Local Secondary Index doesn't exist.

**Common Causes:**
- Index not created on the table
- Incorrect index name
- Index still being created

**Example:**
```php
try {
    $users = User::where('email', 'john@example.com')->get();
} catch (IndexNotFoundException $e) {
    Log::warning('Index not found', [
        'index' => $e->getContext()['index_name'],
        'table' => $e->getContext()['table_name'],
    ]);
    
    // Fallback to scan (less efficient)
    $users = User::all()->where('email', 'john@example.com');
}
```

### BatchOperationException

Thrown when batch operations fail.

**Common Causes:**
- Unprocessed items in response
- Batch size too large
- Throttling

**Example:**
```php
try {
    User::insert([
        ['id' => 'user-1', 'name' => 'John'],
        ['id' => 'user-2', 'name' => 'Jane'],
        // ... up to 25 items
    ]);
} catch (BatchOperationException $e) {
    Log::error('Batch operation failed', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
    
    // Implement retry logic for unprocessed items
}
```

### TimeoutException

Thrown when operations timeout.

**Common Causes:**
- Large table scans
- Complex queries
- Network latency
- DynamoDB throttling

**Example:**
```php
try {
    // Large scan operation
    $count = User::count();
} catch (TimeoutException $e) {
    // Use alternative approach
    $count = User::query()
        ->select(DB::raw('COUNT(*) as count'))
        ->first()
        ->count;
}
```

## Error Handling Examples

### Basic Try-Catch

```php
use Joaquim\LaravelDynamoDb\Exceptions\DynamoDbException;

try {
    $user = User::find('user-123');
    
    if (!$user) {
        return response()->json(['error' => 'User not found'], 404);
    }
    
    return response()->json($user);
} catch (DynamoDbException $e) {
    // All DynamoDB exceptions are logged automatically
    Log::error('Database error', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(),
    ]);
    
    return response()->json([
        'error' => 'Database error occurred',
    ], 500);
}
```

### Specific Exception Handling

```php
use Joaquim\LaravelDynamoDb\Exceptions\ConnectionException;
use Joaquim\LaravelDynamoDb\Exceptions\ValidationException;
use Joaquim\LaravelDynamoDb\Exceptions\OperationException;

try {
    User::create($data);
} catch (ValidationException $e) {
    return response()->json([
        'error' => 'Invalid data',
        'message' => $e->getMessage(),
        'suggestion' => $e->getSuggestion(),
    ], 422);
} catch (ConnectionException $e) {
    return response()->json([
        'error' => 'Database unavailable',
    ], 503);
} catch (OperationException $e) {
    Log::error('Operation failed', [
        'error' => $e->getMessage(),
    ]);
    
    return response()->json([
        'error' => 'Operation failed',
    ], 500);
}
```

### Retry Logic

```php
use Joaquim\LaravelDynamoDb\Exceptions\OperationException;

function createUserWithRetry($data, $maxRetries = 3)
{
    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        try {
            return User::create($data);
        } catch (OperationException $e) {
            $attempt++;
            
            if ($attempt >= $maxRetries) {
                throw $e;
            }
            
            // Exponential backoff
            $delay = pow(2, $attempt) * 100; // 200ms, 400ms, 800ms
            usleep($delay * 1000);
        }
    }
}
```

### Global Exception Handler

Add to `app/Exceptions/Handler.php`:

```php
use Joaquim\LaravelDynamoDb\Exceptions\DynamoDbException;
use Joaquim\LaravelDynamoDb\Exceptions\ValidationException;
use Joaquim\LaravelDynamoDb\Exceptions\ConnectionException;

public function register()
{
    $this->renderable(function (ValidationException $e, $request) {
        return response()->json([
            'error' => 'Validation failed',
            'message' => $e->getMessage(),
            'suggestion' => $e->getSuggestion(),
        ], 422);
    });
    
    $this->renderable(function (ConnectionException $e, $request) {
        return response()->json([
            'error' => 'Service unavailable',
            'message' => 'Database temporarily unavailable',
        ], 503);
    });
    
    $this->renderable(function (DynamoDbException $e, $request) {
        // Generic handler for all DynamoDB exceptions
        return response()->json([
            'error' => 'Database error',
            'message' => $e->getMessage(),
        ], 500);
    });
}
```

## Best Practices

### 1. Always Catch Specific Exceptions First

```php
// Good
try {
    // operation
} catch (ValidationException $e) {
    // handle validation
} catch (ConnectionException $e) {
    // handle connection
} catch (DynamoDbException $e) {
    // handle generic
}

// Bad
try {
    // operation
} catch (DynamoDbException $e) {
    // Too generic - won't catch specific exceptions
}
```

### 2. Use Context Information

```php
try {
    User::create($data);
} catch (DynamoDbException $e) {
    Log::error('Operation failed', [
        'error' => $e->getMessage(),
        'context' => $e->getContext(), // Includes table, operation, etc.
        'suggestion' => $e->getSuggestion(),
    ]);
}
```

### 3. Provide User-Friendly Messages

```php
try {
    User::find($id);
} catch (ValidationException $e) {
    // Don't expose internal errors to users
    return response()->json([
        'error' => 'Invalid request',
        // Log the detailed message internally
    ], 422);
}
```

### 4. Implement Retry Logic for Throttling

```php
use Illuminate\Support\Facades\Log;

function executeWithRetry(callable $operation, $maxRetries = 3)
{
    $attempt = 0;
    
    while ($attempt < $maxRetries) {
        try {
            return $operation();
        } catch (OperationException $e) {
            if (str_contains($e->getMessage(), 'Throughput exceeded')) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                
                usleep(pow(2, $attempt) * 100000); // Exponential backoff
                continue;
            }
            
            throw $e;
        }
    }
}
```

### 5. Configure Logging

In `config/database-dynamodb.php`:

```php
return [
    // Enable exception logging (default: true)
    'log_exceptions' => env('DYNAMODB_LOG_EXCEPTIONS', true),
    
    // Hide sensitive data in logs (default: false)
    'hide_sensitive_logs' => env('DYNAMODB_HIDE_SENSITIVE_LOGS', false),
];
```

## Troubleshooting

### Connection Errors

**Problem:** `ConnectionException: Failed to connect to DynamoDB`

**Solutions:**
1. Check AWS credentials in `.env`
2. Verify AWS region is correct
3. Check network connectivity
4. If using DynamoDB Local, ensure it's running:
   ```bash
   docker run -p 8000:8000 amazon/dynamodb-local
   ```

### Table Not Found

**Problem:** `TableNotFoundException: Table 'users' not found`

**Solutions:**
1. Verify table exists in DynamoDB console
2. Check table name in model's `$table` property
3. Ensure correct AWS region
4. Create table if it doesn't exist

### Validation Errors

**Problem:** `ValidationException: Cannot update without a primary key condition`

**Solutions:**
1. Ensure update/delete includes primary key in WHERE clause:
   ```php
   // Wrong
   User::where('email', 'john@example.com')->update(['name' => 'John']);
   
   // Right
   User::where('id', 'user-123')->update(['name' => 'John']);
   ```

### Throughput Exceeded

**Problem:** `OperationException: Throughput exceeded`

**Solutions:**
1. Implement exponential backoff retry logic
2. Increase provisioned capacity in DynamoDB
3. Consider using on-demand billing
4. Optimize queries to reduce throughput usage

### Query Errors

**Problem:** `QueryException: DynamoDB does not support SQL queries`

**Solutions:**
1. Use Query Builder or Eloquent instead of raw SQL
2. Example:
   ```php
   // Wrong
   DB::select('SELECT * FROM users WHERE id = ?', ['user-123']);
   
   // Right
   User::find('user-123');
   ```

### Index Not Found

**Problem:** `IndexNotFoundException: Index 'email-index' not found`

**Solutions:**
1. Create the index in DynamoDB console
2. Define index in model:
   ```php
   protected $globalSecondaryIndexes = [
       'email-index' => [
           'hash_key' => 'email',
       ],
   ];
   ```
3. Wait for index to finish creating (check status)

## Additional Resources

- [AWS DynamoDB Documentation](https://docs.aws.amazon.com/dynamodb/)
- [Laravel Documentation](https://laravel.com/docs)
- [Package README](../README.md)

## Support

If you encounter issues not covered in this guide:

1. Check existing GitHub issues
2. Review DynamoDB CloudWatch logs
3. Enable debug mode: `APP_DEBUG=true`
4. Check Laravel logs: `storage/logs/laravel.log`
