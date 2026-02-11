<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when an operation times out.
 *
 * This can occur when DynamoDB operations take too long to complete.
 */
class TimeoutException extends DynamoDbException
{
    /**
     * Create a new TimeoutException instance.
     */
    public function __construct(
        string $message = 'Operation timed out',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            code: 408,
            previous: $previous,
            context: $context,
            errorCode: 'TIMEOUT_ERROR',
            suggestion: 'Consider increasing timeout settings, optimizing your query, or implementing retry logic.'
        );
    }
}
