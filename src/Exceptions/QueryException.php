<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when a query operation fails.
 * 
 * This can occur due to invalid query syntax, missing required conditions,
 * or unsupported operations.
 */
class QueryException extends DynamoDbException
{
    /**
     * Create a new QueryException instance.
     *
     * @param string $message
     * @param \Throwable|null $previous
     * @param array $context
     */
    public function __construct(
        string $message = 'Query operation failed',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            code: 400,
            previous: $previous,
            context: $context,
            errorCode: 'QUERY_ERROR',
            suggestion: 'Check your query conditions and ensure they match the table or index key schema.'
        );
    }
}
