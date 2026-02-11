<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when a batch operation fails.
 *
 * This includes BatchGetItem, BatchWriteItem operations.
 */
class BatchOperationException extends DynamoDbException
{
    /**
     * Create a new BatchOperationException instance.
     */
    public function __construct(
        string $message = 'Batch operation failed',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            code: 500,
            previous: $previous,
            context: $context,
            errorCode: 'BATCH_OPERATION_ERROR',
            suggestion: 'Check for unprocessed items in the response and implement retry logic for failed items.'
        );
    }
}
