<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model as DynamoDbModel;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Index\IndexResolver;
use Joaquim\LaravelDynamoDb\Exceptions\ValidationException;

class Grammar extends BaseGrammar
{
    /**
     * IndexResolver instance.
     */
    protected ?IndexResolver $indexResolver = null;

    /**
     * Get or create IndexResolver.
     */
    protected function getIndexResolver(?BaseBuilder $query = null): ?IndexResolver
    {
        if (! $query) {
            return null;
        }

        // Tentar obter model do query builder
        $model = $this->getModelFromQuery($query);

        if (! $model) {
            return null;
        }

        if (! $this->indexResolver) {
            $this->indexResolver = new IndexResolver($model);
        } else {
            $this->indexResolver->setModel($model);
        }

        return $this->indexResolver;
    }

    /**
     * Get model instance from query.
     */
    protected function getModelFromQuery(BaseBuilder $query): ?DynamoDbModel
    {
        // O DynamoDbBuilder tem método getModel()
        if ($query instanceof Builder && method_exists($query, 'getModel')) {
            $model = $query->getModel();
            if ($model instanceof DynamoDbModel) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Compile a select query into DynamoDB operation.
     *
     * @return array
     */
    public function compileSelect(BaseBuilder $query)
    {
        // Determinar qual operação usar (GetItem, Query, Scan)
        $operation = $this->determineOperation($query);

        $params = [
            'TableName' => $this->getTableName($query),
        ];

        switch ($operation) {
            case 'BatchGetItem':
                return [
                    'operation' => 'BatchGetItem',
                    'params' => $this->compileBatchGetItem($query, $params),
                ];

            case 'GetItem':
                return [
                    'operation' => 'GetItem',
                    'params' => $this->compileGetItem($query, $params),
                ];

            case 'Query':
                return [
                    'operation' => 'Query',
                    'params' => $this->compileQuery($query, $params),
                ];

            case 'Scan':
            default:
                return [
                    'operation' => 'Scan',
                    'params' => $this->compileScan($query, $params),
                ];
        }
    }

    /**
     * Determine which DynamoDB operation to use.
     *
     * @return string
     */
    protected function determineOperation(BaseBuilder $query)
    {
        $wheres = $query->wheres;

        // BatchGetItem: quando há apenas um whereIn na primary key
        if (count($wheres) === 1 &&
            $wheres[0]['type'] === 'In') {
            $resolver = $this->getIndexResolver($query);
            if ($resolver && $resolver->isPartitionKey($wheres[0]['column'])) {
                return 'BatchGetItem';
            }
        }

        // GetItem: quando há apenas uma condição de igualdade na primary key
        if (count($wheres) === 1 &&
            $wheres[0]['type'] === 'Basic' &&
            $wheres[0]['operator'] === '=') {
            // Verificar se é realmente a primary key
            $resolver = $this->getIndexResolver($query);
            if ($resolver && $resolver->isPartitionKey($wheres[0]['column'])) {
                return 'GetItem';
            }
        }

        // Tentar encontrar índice usando IndexResolver
        $resolver = $this->getIndexResolver($query);
        if ($resolver) {
            $indexMatch = $resolver->findBestIndex($query);
            if ($indexMatch) {
                // Se encontrou índice, pode usar Query
                if ($indexMatch['index_type'] === 'primary' &&
                    count($indexMatch['key_conditions']) === 1 &&
                    ! $resolver->getSortKey()) {
                    return 'GetItem'; // Primary key simples sem sort key
                }

                return 'Query'; // Usar Query com índice
            }
        }

        // Por último, usar Scan (menos eficiente)
        return 'Scan';
    }

    /**
     * Compile BatchGetItem operation.
     *
     * @return array
     */
    protected function compileBatchGetItem(BaseBuilder $query, array $params)
    {
        $where = $query->wheres[0];
        $key = $where['column'];
        $values = $where['values'] ?? [];

        // Preparar as chaves para BatchGetItem
        // DynamoDB BatchGetItem tem limite de 100 itens por requisição
        $keys = array_map(function ($value) use ($key) {
            return [$key => $value];
        }, $values);

        $params['Keys'] = $keys;

        // Adicionar ProjectionExpression se houver select específico
        $this->addProjectionExpression($query, $params);

        return $params;
    }

    /**
     * Compile GetItem operation.
     *
     * @return array
     */
    protected function compileGetItem(BaseBuilder $query, array $params)
    {
        $where = $query->wheres[0];
        $key = $where['column'];
        $value = $where['value'];

        $params['Key'] = [
            $key => $value,
        ];

        // Adicionar ProjectionExpression se houver select específico
        $this->addProjectionExpression($query, $params);

        return $params;
    }

    /**
     * Compile Query operation.
     *
     * @return array
     */
    protected function compileQuery(BaseBuilder $query, array $params)
    {
        $resolver = $this->getIndexResolver($query);

        if (! $resolver) {
            // Fallback para Scan se não conseguir resolver índices
            return $this->compileScan($query, $params);
        }

        $indexMatch = $resolver->findBestIndex($query);

        if (! $indexMatch) {
            return $this->compileScan($query, $params);
        }

        // Compilar KeyConditionExpression a partir das key conditions
        $keyConditions = $this->compileKeyConditions(
            $indexMatch['key_conditions'],
            $params
        );

        // Se usar GSI ou LSI, especificar IndexName
        if ($indexMatch['index_type'] !== 'primary' && $indexMatch['index_name']) {
            $params['IndexName'] = $indexMatch['index_name'];
        }

        // KeyConditionExpression é obrigatório para Query
        if (! empty($keyConditions['expression'])) {
            $params['KeyConditionExpression'] = $keyConditions['expression'];
            $params['ExpressionAttributeNames'] = array_merge(
                $params['ExpressionAttributeNames'] ?? [],
                $keyConditions['attributeNames']
            );
            $params['ExpressionAttributeValues'] = array_merge(
                $params['ExpressionAttributeValues'] ?? [],
                $keyConditions['attributeValues']
            );
        }

        // Compilar FilterExpression a partir das filter conditions
        // Usar contador maior que o usado em KeyConditionExpression para evitar conflitos
        $baseCounter = count($keyConditions['attributeNames'] ?? []);

        if (! empty($indexMatch['filter_conditions'])) {
            $filterConditions = $this->compileWheresForDynamoDb(
                $this->createQueryFromWheres($query, $indexMatch['filter_conditions']),
                $baseCounter
            );

            if (! empty($filterConditions['expression'])) {
                $params['FilterExpression'] = $filterConditions['expression'];
                $params['ExpressionAttributeNames'] = array_merge(
                    $params['ExpressionAttributeNames'] ?? [],
                    $filterConditions['attributeNames']
                );
                $params['ExpressionAttributeValues'] = array_merge(
                    $params['ExpressionAttributeValues'] ?? [],
                    $filterConditions['attributeValues']
                );
            }
        } else {
            // Se não há filter conditions específicas, compilar todas as condições
            // que não foram usadas como key conditions
            $remainingFilters = $this->getRemainingFilters(
                $query,
                $indexMatch['key_conditions']
            );

            if (! empty($remainingFilters)) {
                $filterConditions = $this->compileWheresForDynamoDb(
                    $this->createQueryFromWheres($query, $remainingFilters),
                    $baseCounter
                );
                if (! empty($filterConditions['expression'])) {
                    $params['FilterExpression'] = $filterConditions['expression'];
                    $params['ExpressionAttributeNames'] = array_merge(
                        $params['ExpressionAttributeNames'] ?? [],
                        $filterConditions['attributeNames']
                    );
                    $params['ExpressionAttributeValues'] = array_merge(
                        $params['ExpressionAttributeValues'] ?? [],
                        $filterConditions['attributeValues']
                    );
                }
            }
        }

        // Se for count, usar Select COUNT
        if (! is_null($query->aggregate) && isset($query->aggregate['function']) && $query->aggregate['function'] === 'count') {
            $params['Select'] = 'COUNT';
        } else {
            // Adicionar ProjectionExpression se houver select específico (apenas se não for COUNT)
            $this->addProjectionExpression($query, $params);
        }

        // Limit
        if ($query->limit !== null) {
            $params['Limit'] = $query->limit;
        }

        // OrderBy: DynamoDB só permite ordenação pelo Sort Key do índice usado
        // Usar ScanIndexForward (true = ascending, false = descending)
        $this->compileOrderBy($query, $params, $indexMatch);

        return $params;
    }

    /**
     * Compile key conditions into KeyConditionExpression.
     */
    protected function compileKeyConditions(array $keyConditions, array &$params): array
    {
        $expression = [];
        $attributeNames = [];
        $attributeValues = [];
        $counter = 0;

        foreach ($keyConditions as $condition) {
            $counter++;
            $nameKey = "#attr{$counter}";
            $valueKey = ":val{$counter}";

            $column = $condition['column'];
            $operator = $condition['operator'] ?? '=';
            $value = $condition['value'];

            // Partition key sempre usa igualdade
            if (($condition['key_type'] ?? null) === 'partition' || $operator === '=') {
                $expression[] = "{$nameKey} = {$valueKey}";
            }
            // Sort key pode usar range operators
            elseif (in_array($operator, ['<', '<=', '>', '>=', 'between', 'begins_with'])) {
                if ($operator === 'begins_with') {
                    $expression[] = "begins_with({$nameKey}, {$valueKey})";
                } elseif ($operator === 'between') {
                    // Between precisa de 2 valores
                    $counter++;
                    $valueKey2 = ":val{$counter}";
                    $value2 = is_array($value) ? $value[1] : $value;
                    $expression[] = "{$nameKey} BETWEEN {$valueKey} AND {$valueKey2}";
                    $attributeValues[$valueKey2] = $value2;
                } else {
                    $expression[] = "{$nameKey} {$operator} {$valueKey}";
                }
            } else {
                $expression[] = "{$nameKey} = {$valueKey}";
            }

            $attributeNames[$nameKey] = $column;
            $attributeValues[$valueKey] = $value;
        }

        return [
            'expression' => implode(' AND ', $expression),
            'attributeNames' => $attributeNames,
            'attributeValues' => $attributeValues,
        ];
    }

    /**
     * Get remaining filters that weren't used in key conditions.
     */
    protected function getRemainingFilters(BaseBuilder $query, array $keyConditions): array
    {
        $keyColumns = array_map(fn ($kc) => $kc['column'], $keyConditions);

        return array_filter($query->wheres, function ($where) use ($keyColumns) {
            return ! in_array($where['column'], $keyColumns);
        });
    }

    /**
     * Create a query builder with specific where clauses.
     */
    protected function createQueryFromWheres(BaseBuilder $query, array $wheres): BaseBuilder
    {
        $newQuery = clone $query;
        $newQuery->wheres = $wheres;

        return $newQuery;
    }

    /**
     * Compile Scan operation.
     *
     * @return array
     */
    protected function compileScan(BaseBuilder $query, array $params)
    {
        // FilterExpression será compilado a partir dos wheres
        $filterExpression = $this->compileWheresForDynamoDb($query, 0);

        if (! empty($filterExpression['expression'])) {
            $params['FilterExpression'] = $filterExpression['expression'];
            $params['ExpressionAttributeNames'] = $filterExpression['attributeNames'] ?? [];
            $params['ExpressionAttributeValues'] = $filterExpression['attributeValues'] ?? [];
        }

        // Se for count, usar Select COUNT (mais eficiente)
        if (! is_null($query->aggregate) && isset($query->aggregate['function']) && $query->aggregate['function'] === 'count') {
            $params['Select'] = 'COUNT';
        } else {
            // Adicionar ProjectionExpression se houver select específico (apenas se não for COUNT)
            $this->addProjectionExpression($query, $params);
        }

        // Limit
        if ($query->limit !== null) {
            $params['Limit'] = $query->limit;
        }

        return $params;
    }

    /**
     * Add ProjectionExpression to params if query has specific columns selected.
     */
    protected function addProjectionExpression(BaseBuilder $query, array &$params): void
    {
        // Verificar se há select específico (não é ['*'] ou vazio)
        if (empty($query->columns) || $query->columns === ['*']) {
            return;
        }

        $projectionParts = [];
        $attributeNames = $params['ExpressionAttributeNames'] ?? [];
        $counter = count($attributeNames);

        foreach ($query->columns as $column) {
            // Ignorar colunas com alias ou funções agregadas
            if (str_contains($column, ' as ') || str_contains($column, '(')) {
                continue;
            }

            // Extrair nome da coluna (remover alias se houver)
            $columnName = trim(explode(' as ', $column)[0]);

            // Ignorar colunas inválidas
            if (empty($columnName) || $columnName === '*') {
                continue;
            }

            $counter++;
            $nameKey = "#attr{$counter}";

            $projectionParts[] = $nameKey;
            $attributeNames[$nameKey] = $columnName;
        }

        // Apenas adicionar ProjectionExpression se houver colunas válidas
        if (! empty($projectionParts)) {
            $params['ProjectionExpression'] = implode(', ', $projectionParts);
            $params['ExpressionAttributeNames'] = $attributeNames;
        }
    }

    /**
     * Compile where clauses to FilterExpression for DynamoDB.
     *
     * @param  int  $baseCounter  Contador base para evitar conflitos com KeyConditionExpression
     */
    protected function compileWheresForDynamoDb(BaseBuilder $query, int $baseCounter = 0): array
    {
        $expression = [];
        $attributeNames = [];
        $attributeValues = [];
        $counter = $baseCounter;

        foreach ($query->wheres as $where) {
            $counter++;
            $nameKey = "#attr{$counter}";
            $valueKey = ":val{$counter}";

            switch ($where['type']) {
                case 'Basic':
                    $operator = $where['operator'];
                    $column = $where['column'];
                    $value = $where['value'];

                    // Tratar LIKE para FilterExpression (DynamoDB usa contains() para %texto%)
                    if ($operator === 'like') {
                        // Se começa e termina com %, usar contains()
                        if (str_starts_with($value, '%') && str_ends_with($value, '%')) {
                            $value = trim($value, '%');
                            $expression[] = "contains({$nameKey}, {$valueKey})";
                        }
                        // Se começa com %, usar ends_with() (não suportado diretamente, usar Scan)
                        // Por enquanto, usar contains como fallback
                        elseif (str_starts_with($value, '%')) {
                            $value = trim($value, '%');
                            $expression[] = "contains({$nameKey}, {$valueKey})";
                        }
                        // Se termina com %, usar begins_with() (não é FilterExpression, seria KeyConditionExpression)
                        // Por enquanto, usar contains como fallback
                        elseif (str_ends_with($value, '%')) {
                            $value = rtrim($value, '%');
                            $expression[] = "contains({$nameKey}, {$valueKey})";
                        }
                        // Sem %, tratar como igualdade
                        else {
                            $expression[] = "{$nameKey} = {$valueKey}";
                        }
                    } else {
                        $operator = $this->convertOperator($operator);
                        $expression[] = "{$nameKey} {$operator} {$valueKey}";
                    }

                    $attributeNames[$nameKey] = $column;
                    $attributeValues[$valueKey] = $value;
                    break;
            }
        }

        return [
            'expression' => implode(' AND ', $expression),
            'attributeNames' => $attributeNames,
            'attributeValues' => $attributeValues,
        ];
    }

    /**
     * Convert SQL operator to DynamoDB operator.
     */
    protected function convertOperator(string $operator): string
    {
        return match ($operator) {
            '=', '==', '===' => '=',
            '!=' => '<>',
            '<' => '<',
            '<=' => '<=',
            '>' => '>',
            '>=' => '>=',
            default => '=',
        };
    }

    /**
     * Compile an insert statement.
     *
     * @return array
     */
    public function compileInsert(BaseBuilder $query, array $values)
    {
        $table = $this->getTableName($query);

        // Validate that values are not empty
        if (empty($values)) {
            throw new ValidationException(
                message: 'Cannot insert empty values',
                context: ['table' => $table],
                suggestion: 'Provide at least one attribute to insert'
            );
        }

        // Se for array de arrays (batch insert), retorna todos
        if (isset($values[0]) && is_array($values[0])) {
            // Validate each item in batch
            foreach ($values as $index => $item) {
                if (empty($item)) {
                    throw new ValidationException(
                        message: "Cannot insert empty item at index {$index}",
                        context: ['table' => $table, 'index' => $index],
                        suggestion: 'Ensure all items in batch insert contain data'
                    );
                }
            }

            return array_map(fn ($value) => [
                'params' => [
                    'TableName' => $table,
                    'Item' => $value,
                ],
            ], $values);
        }

        // Single insert
        return [
            'params' => [
                'TableName' => $table,
                'Item' => $values,
            ],
        ];
    }

    /**
     * Compile an update statement.
     *
     * @return array
     */
    public function compileUpdate(BaseBuilder $query, array $values)
    {
        // Validate that values are not empty
        if (empty($values)) {
            throw new ValidationException(
                message: 'Cannot update with empty values',
                context: ['table' => $this->getTableName($query)],
                suggestion: 'Provide at least one attribute to update'
            );
        }

        // Por enquanto, simples - será melhorado
        $key = $this->extractKeyFromWheres($query);

        // Validate that key was extracted
        if (empty($key)) {
            throw new ValidationException(
                message: 'Cannot update without a primary key condition',
                context: [
                    'table' => $this->getTableName($query),
                    'wheres' => $query->wheres,
                ],
                suggestion: 'Update operations in DynamoDB require the primary key in the WHERE clause'
            );
        }

        $updateExpression = [];
        $expressionAttributeValues = [];
        $counter = 0;

        foreach ($values as $column => $value) {
            $counter++;
            $valueKey = ":val{$counter}";
            $updateExpression[] = "#attr{$counter} = {$valueKey}";
            $expressionAttributeValues[$valueKey] = $value;
        }

        return [
            'params' => [
                'TableName' => $this->getTableName($query),
                'Key' => $key,
                'UpdateExpression' => 'SET '.implode(', ', $updateExpression),
                'ExpressionAttributeNames' => array_combine(
                    array_map(fn ($i) => "#attr{$i}", range(1, $counter)),
                    array_keys($values)
                ),
                'ExpressionAttributeValues' => $expressionAttributeValues,
            ],
        ];
    }

    /**
     * Compile a delete statement.
     *
     * @return array
     */
    public function compileDelete(BaseBuilder $query)
    {
        $key = $this->extractKeyFromWheres($query);

        // Validate that key was extracted
        if (empty($key)) {
            throw new ValidationException(
                message: 'Cannot delete without a primary key condition',
                context: [
                    'table' => $this->getTableName($query),
                    'wheres' => $query->wheres,
                ],
                suggestion: 'Delete operations in DynamoDB require the primary key in the WHERE clause'
            );
        }

        return [
            'params' => [
                'TableName' => $this->getTableName($query),
                'Key' => $key,
            ],
        ];
    }

    /**
     * Get table name from query.
     */
    protected function getTableName(BaseBuilder $query): string
    {
        return $query->from;
    }

    /**
     * Extract key from where clauses (simplificado).
     */
    protected function extractKeyFromWheres(BaseBuilder $query): array
    {
        $key = [];
        foreach ($query->wheres as $where) {
            if ($where['type'] === 'Basic' && $where['operator'] === '=') {
                $key[$where['column']] = $where['value'];
            }
        }

        return $key;
    }

    /**
     * Compile orderBy clause for DynamoDB Query operations.
     *
     * No DynamoDB, orderBy só funciona para operações Query e apenas pelo Sort Key do índice usado.
     * Usa ScanIndexForward: true = ascending, false = descending.
     */
    protected function compileOrderBy(BaseBuilder $query, array &$params, array $indexMatch): void
    {
        // Verificar se há orderBy no query
        if (empty($query->orders)) {
            return;
        }

        // Pegar o primeiro orderBy (DynamoDB só suporta ordenação por um campo - o Sort Key)
        $orderBy = $query->orders[0];
        $orderColumn = $orderBy['column'] ?? null;
        $orderDirection = strtolower($orderBy['direction'] ?? 'asc');

        if (! $orderColumn) {
            return;
        }

        // Obter o Sort Key do índice sendo usado
        $indexSortKey = $indexMatch['sort_key'] ?? null;

        // Se o orderBy for pelo Sort Key do índice, usar ScanIndexForward
        if ($indexSortKey && $orderColumn === $indexSortKey) {
            // ScanIndexForward: true = ascending, false = descending
            $params['ScanIndexForward'] = ($orderDirection === 'asc');
        }
        // Se não for pelo Sort Key, não podemos ordenar nativamente no DynamoDB
        // A ordenação será feita em memória no Processor (se necessário)
        // Por enquanto, apenas ignoramos (não adicionamos ScanIndexForward)
    }
}
