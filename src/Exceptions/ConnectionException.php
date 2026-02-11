<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when there are connection problems with DynamoDB.
 *
 * This can occur due to network issues, invalid credentials, or service unavailability.
 */
class ConnectionException extends DynamoDbException
{
    /**
     * Create a new ConnectionException instance.
     */
    public function __construct(
        string $message = 'Failed to connect to DynamoDB',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            code: 503,
            previous: $previous,
            context: $context,
            errorCode: 'CONNECTION_ERROR',
            suggestion: 'Check your AWS credentials, region configuration, and network connectivity. If using DynamoDB Local, ensure it is running.'
        );
    }
}
