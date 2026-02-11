<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when pagination encounters an error.
 *
 * This can occur when pagination tokens are invalid or expired.
 */
class PaginationException extends DynamoDbException
{
    /**
     * Create a new PaginationException instance.
     */
    public function __construct(
        string $message = 'Pagination failed',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            code: 400,
            previous: $previous,
            context: $context,
            errorCode: 'PAGINATION_ERROR',
            suggestion: 'Check if the pagination token is valid and not expired. Tokens are only valid for a limited time.'
        );
    }
}
