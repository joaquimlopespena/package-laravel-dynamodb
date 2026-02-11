<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when a requested table is not found.
 * 
 * This occurs when trying to perform operations on a table that doesn't exist.
 */
class TableNotFoundException extends DynamoDbException
{
    /**
     * Create a new TableNotFoundException instance.
     *
     * @param string $tableName
     * @param \Throwable|null $previous
     * @param array $context
     */
    public function __construct(
        string $tableName = '',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        $message = $tableName
            ? "Table '{$tableName}' not found"
            : 'Table not found';

        $context = array_merge($context, array_filter([
            'table_name' => $tableName,
        ]));

        parent::__construct(
            message: $message,
            code: 404,
            previous: $previous,
            context: $context,
            errorCode: 'TABLE_NOT_FOUND',
            suggestion: 'Verify the table name in your model configuration or create the table in DynamoDB.'
        );
    }
}
