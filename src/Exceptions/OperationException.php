<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when a CRUD operation fails.
 *
 * This includes failures in insert, update, delete operations.
 */
class OperationException extends DynamoDbException
{
    /**
     * Create a new OperationException instance.
     */
    public function __construct(
        string $message = 'DynamoDB operation failed',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            code: 500,
            previous: $previous,
            context: $context,
            errorCode: 'OPERATION_ERROR',
            suggestion: 'Review the operation parameters and AWS error details for more information.'
        );
    }
}
