<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when data validation fails.
 *
 * This occurs when attempting to insert/update data that doesn't match
 * the expected schema or contains invalid values.
 */
class ValidationException extends DynamoDbException
{
    /**
     * Create a new ValidationException instance.
     */
    public function __construct(
        string $message = 'Data validation failed',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct(
            message: $message,
            code: 422,
            previous: $previous,
            context: $context,
            errorCode: 'VALIDATION_ERROR',
            suggestion: 'Ensure your data matches the expected schema and all required attributes are present.'
        );
    }
}
