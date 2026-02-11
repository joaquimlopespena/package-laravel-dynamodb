<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

/**
 * Exception thrown when a requested index is not found.
 *
 * This occurs when trying to query using a Global or Local Secondary Index
 * that doesn't exist on the table.
 */
class IndexNotFoundException extends DynamoDbException
{
    /**
     * Create a new IndexNotFoundException instance.
     */
    public function __construct(
        string $indexName = '',
        string $tableName = '',
        ?\Throwable $previous = null,
        array $context = []
    ) {
        $message = $indexName && $tableName
            ? "Index '{$indexName}' not found on table '{$tableName}'"
            : 'Index not found';

        $context = array_merge($context, array_filter([
            'index_name' => $indexName,
            'table_name' => $tableName,
        ]));

        parent::__construct(
            message: $message,
            code: 404,
            previous: $previous,
            context: $context,
            errorCode: 'INDEX_NOT_FOUND',
            suggestion: 'Verify the index name and ensure the index exists on the table. You can check available indexes using DescribeTable API.'
        );
    }
}
