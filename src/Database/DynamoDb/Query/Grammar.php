<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model as DynamoDbModel;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Index\IndexResolver;

/**
 * DynamoDB Query Grammar Class.
 * 
 * Compila queries do Laravel/Eloquent em operações DynamoDB nativas.
 * Resolve automaticamente qual operação usar (GetItem, Query, Scan, BatchGetItem)
 * baseado nas condições da query e índices disponíveis.
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Query
 * @since 1.0.0
 */
class Grammar extends BaseGrammar
{
    /**
     * IndexResolver instance.
     * 
     * Resolve automaticamente o melhor índice (Primary, GSI ou LSI)
     * para executar uma query baseado nas condições WHERE.
     *
     * @var IndexResolver|null
     */
    protected ?IndexResolver $indexResolver = null;

    /**
     * Get or create IndexResolver.
     * 
     * Obtém ou cria uma instância do IndexResolver para o model associado
     * à query. O IndexResolver analisa índices disponíveis e escolhe o mais
     * eficiente para executar a query.
     *
     * @param BaseBuilder|null $query Query builder com model associado
     * 
     * @return IndexResolver|null Instância do resolver ou null se não houver model
     * 
     * @since 1.0.0
     */
    protected function getIndexResolver(?BaseBuilder $query = null): ?IndexResolver
    {
        if (!$query) {
            return null;
        }

        // Tentar obter model do query builder
        $model = $this->getModelFromQuery($query);

        if (!$model) {
            return null;
        }

        if (!$this->indexResolver) {
            $this->indexResolver = new IndexResolver($model);
        } else {
            $this->indexResolver->setModel($model);
        }

        return $this->indexResolver;
    }

    /**
     * Get model instance from query.
     * 
     * Extrai a instância do Model Eloquent do Query Builder,
     * necessária para resolver índices e configurações específicas do DynamoDB.
     *
     * @param BaseBuilder $query Query builder do Laravel
     * 
     * @return DynamoDbModel|null Model DynamoDB ou null se não for DynamoDbBuilder
     * 
     * @since 1.0.0
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
     * Analisa a query e compila para a operação DynamoDB mais eficiente:
     * - GetItem: busca por chave primária simples
     * - BatchGetItem: busca múltiplos itens por chaves primárias
     * - Query: busca com índice (Primary, GSI ou LSI)
     * - Scan: varredura completa da tabela (menos eficiente)
     *
     * @param BaseBuilder $query Query builder do Laravel
     * 
     * @return array Array com 'operation' (tipo de operação) e 'params' (parâmetros DynamoDB)
     * 
     * @example
     * // GetItem
     * $compiled = $grammar->compileSelect($query->where('id', 'user123'));
     * // ['operation' => 'GetItem', 'params' => ['TableName' => 'users', 'Key' => ['id' => 'user123']]]
     * 
     * // Query com índice
     * $compiled = $grammar->compileSelect($query->where('status', 'active'));
     * // ['operation' => 'Query', 'params' => ['TableName' => 'users', 'IndexName' => 'status-index', ...]]
     * 
     * @since 1.0.0
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
     * Analisa as condições WHERE da query e determina a operação DynamoDB
     * mais eficiente. Usa IndexResolver para encontrar índices disponíveis.
     *
     * @param BaseBuilder $query Query builder com condições WHERE
     * 
     * @return string Nome da operação: 'GetItem', 'BatchGetItem', 'Query' ou 'Scan'
     * 
     * @since 1.0.0
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
                    !$resolver->getSortKey()) {
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
     * Compila uma operação BatchGetItem para buscar múltiplos itens
     * por suas chaves primárias. Limitado a 100 itens por requisição.
     *
     * @param BaseBuilder $query Query com whereIn na partition key
     * @param array $params Parâmetros base incluindo TableName
     * 
     * @return array Parâmetros completos para BatchGetItem
     * 
     * @since 1.0.0
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
     * Compila uma operação GetItem para buscar um único item
     * por sua chave primária. A operação mais eficiente no DynamoDB.
     *
     * @param BaseBuilder $query Query com where na partition key
     * @param array $params Parâmetros base incluindo TableName
     * 
     * @return array Parâmetros completos para GetItem com Key
     * 
     * @since 1.0.0
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
     * Compila uma operação Query usando índice (Primary Key, GSI ou LSI).
     * Query é muito mais eficiente que Scan pois usa índices para busca.
     * Compila KeyConditionExpression para condições do índice e FilterExpression
     * para filtros adicionais.
     *
     * @param BaseBuilder $query Query builder do Laravel
     * @param array $params Parâmetros base incluindo TableName
     * 
     * @return array Parâmetros completos para Query incluindo KeyConditionExpression, FilterExpression, IndexName, etc
     * 
     * @example
     * // Query com GSI
     * $params = $this->compileQuery($query->where('status', 'active')->where('created_at', '>', $date));
     * // Usa status-created_at-index se disponível
     * 
     * @since 1.0.0
     */
    protected function compileQuery(BaseBuilder $query, array $params)
    {
        $resolver = $this->getIndexResolver($query);

        if (!$resolver) {
            // Fallback para Scan se não conseguir resolver índices
            return $this->compileScan($query, $params);
        }

        $indexMatch = $resolver->findBestIndex($query);

        if (!$indexMatch) {
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
        if (!empty($keyConditions['expression'])) {
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

        if (!empty($indexMatch['filter_conditions'])) {
            $filterConditions = $this->compileWheresForDynamoDb(
                $this->createQueryFromWheres($query, $indexMatch['filter_conditions']),
                $baseCounter
            );

            if (!empty($filterConditions['expression'])) {
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

            if (!empty($remainingFilters)) {
                $filterConditions = $this->compileWheresForDynamoDb(
                    $this->createQueryFromWheres($query, $remainingFilters),
                    $baseCounter
                );
                if (!empty($filterConditions['expression'])) {
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
     * 
     * Compila condições de chave (partition key e sort key) em KeyConditionExpression.
     * Partition key sempre usa igualdade (=). Sort key pode usar operadores de range
     * (<, <=, >, >=, between, begins_with).
     *
     * @param array $keyConditions Array de condições de chave do IndexResolver
     * @param array $params Referência aos parâmetros da query (não modificado aqui)
     * 
     * @return array Array com 'expression', 'attributeNames' e 'attributeValues'
     * 
     * @since 1.0.0
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
     * 
     * Filtra condições WHERE que não foram usadas como KeyConditionExpression
     * para usar como FilterExpression. FilterExpression é aplicado após a query
     * e consome RCU (Read Capacity Units).
     *
     * @param BaseBuilder $query Query builder com todas as condições
     * @param array $keyConditions Condições usadas em KeyConditionExpression
     * 
     * @return array Array de condições WHERE restantes
     * 
     * @since 1.0.0
     */
    protected function getRemainingFilters(BaseBuilder $query, array $keyConditions): array
    {
        $keyColumns = array_map(fn($kc) => $kc['column'], $keyConditions);

        return array_filter($query->wheres, function($where) use ($keyColumns) {
            return !in_array($where['column'], $keyColumns);
        });
    }

    /**
     * Create a query builder with specific where clauses.
     * 
     * Clona o query builder e substitui as condições WHERE.
     * Útil para compilar FilterExpression separadamente das KeyConditions.
     *
     * @param BaseBuilder $query Query builder original
     * @param array $wheres Array de condições WHERE para usar
     * 
     * @return BaseBuilder Novo query builder com condições especificadas
     * 
     * @since 1.0.0
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
     * Compila uma operação Scan que varre toda a tabela.
     * Esta é a operação menos eficiente do DynamoDB e deve ser evitada
     * em produção. Use índices sempre que possível.
     *
     * @param BaseBuilder $query Query builder do Laravel
     * @param array $params Parâmetros base incluindo TableName
     * 
     * @return array Parâmetros completos para Scan com FilterExpression
     * 
     * @since 1.0.0
     */
    protected function compileScan(BaseBuilder $query, array $params)
    {
        // FilterExpression será compilado a partir dos wheres
        $filterExpression = $this->compileWheresForDynamoDb($query, 0);

        if (!empty($filterExpression['expression'])) {
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
     * 
     * Adiciona ProjectionExpression aos parâmetros quando a query especifica
     * colunas específicas (select). Reduz uso de RCU retornando apenas
     * os atributos necessários.
     *
     * @param BaseBuilder $query Query com colunas especificadas
     * @param array $params Referência aos parâmetros (modificado diretamente)
     * 
     * @return void
     * 
     * @since 1.0.0
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
        if (!empty($projectionParts)) {
            $params['ProjectionExpression'] = implode(', ', $projectionParts);
            $params['ExpressionAttributeNames'] = $attributeNames;
        }
    }

    /**
     * Compile where clauses to FilterExpression for DynamoDB.
     * 
     * Compila condições WHERE do Laravel em FilterExpression do DynamoDB.
     * Suporta operadores básicos (=, <>, <, <=, >, >=) e LIKE (usando contains).
     * FilterExpression é aplicado após a query e consome RCU.
     *
     * @param BaseBuilder $query Query builder com condições WHERE
     * @param int $baseCounter Contador base para evitar conflitos com KeyConditionExpression
     * 
     * @return array Array com 'expression', 'attributeNames' e 'attributeValues'
     * 
     * @since 1.0.0
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
     * 
     * Converte operadores SQL padrão para operadores suportados pelo DynamoDB.
     * DynamoDB usa <> em vez de != para desigualdade.
     *
     * @param string $operator Operador SQL (=, !=, <, <=, >, >=)
     * 
     * @return string Operador DynamoDB correspondente
     * 
     * @since 1.0.0
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
     * Compila uma operação de insert (PutItem) para DynamoDB.
     * Suporta tanto insert único quanto batch insert (múltiplos itens).
     *
     * @param BaseBuilder $query Query builder do Laravel
     * @param array $values Array de valores a inserir (pode ser array simples ou array de arrays para batch)
     * 
     * @return array Array com params para PutItem ou array de arrays para batch insert
     * 
     * @example
     * // Insert único
     * $compiled = $grammar->compileInsert($query, ['id' => 'user123', 'name' => 'John']);
     * 
     * // Batch insert
     * $compiled = $grammar->compileInsert($query, [
     *     ['id' => 'user1', 'name' => 'John'],
     *     ['id' => 'user2', 'name' => 'Jane']
     * ]);
     * 
     * @since 1.0.0
     */
    public function compileInsert(BaseBuilder $query, array $values)
    {
        $table = $this->getTableName($query);

        // Se for array de arrays (batch insert), retorna todos
        if (isset($values[0]) && is_array($values[0])) {
            return array_map(fn($value) => [
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
     * Compila uma operação UpdateItem para atualizar atributos específicos
     * de um item existente. Usa UpdateExpression (SET) para modificar apenas
     * os campos especificados.
     *
     * @param BaseBuilder $query Query builder com WHERE na chave primária
     * @param array $values Array associativo de campos e valores a atualizar
     * 
     * @return array Array com params para UpdateItem incluindo Key, UpdateExpression, ExpressionAttributeNames e ExpressionAttributeValues
     * 
     * @example
     * $compiled = $grammar->compileUpdate(
     *     $query->where('id', 'user123'),
     *     ['name' => 'John Updated', 'email' => 'john.updated@example.com']
     * );
     * 
     * @since 1.0.0
     */
    public function compileUpdate(BaseBuilder $query, array $values)
    {
        // Por enquanto, simples - será melhorado
        $key = $this->extractKeyFromWheres($query);

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
                'UpdateExpression' => 'SET ' . implode(', ', $updateExpression),
                'ExpressionAttributeNames' => array_combine(
                    array_map(fn($i) => "#attr{$i}", range(1, $counter)),
                    array_keys($values)
                ),
                'ExpressionAttributeValues' => $expressionAttributeValues,
            ],
        ];
    }

    /**
     * Compile a delete statement.
     * 
     * Compila uma operação DeleteItem para remover um item pela chave primária.
     *
     * @param BaseBuilder $query Query builder com WHERE na chave primária
     * 
     * @return array Array com params para DeleteItem incluindo TableName e Key
     * 
     * @example
     * $compiled = $grammar->compileDelete($query->where('id', 'user123'));
     * 
     * @since 1.0.0
     */
    public function compileDelete(BaseBuilder $query)
    {
        $key = $this->extractKeyFromWheres($query);

        return [
            'params' => [
                'TableName' => $this->getTableName($query),
                'Key' => $key,
            ],
        ];
    }

    /**
     * Get table name from query.
     * 
     * Extrai o nome da tabela do Query Builder.
     *
     * @param BaseBuilder $query Query builder do Laravel
     * 
     * @return string Nome da tabela DynamoDB
     * 
     * @since 1.0.0
     */
    protected function getTableName(BaseBuilder $query): string
    {
        return $query->from;
    }

    /**
     * Extract key from where clauses (simplificado).
     * 
     * Extrai a chave primária (partition key e sort key se existir) das
     * condições WHERE. Usado para operações GetItem, UpdateItem e DeleteItem.
     *
     * @param BaseBuilder $query Query builder com WHERE na chave primária
     * 
     * @return array Array associativo com chave primária (partition key e sort key)
     * 
     * @since 1.0.0
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
     * Compila ordenação para operações Query no DynamoDB. DynamoDB só suporta
     * ordenação nativa pelo Sort Key do índice sendo usado. Usa ScanIndexForward
     * para controlar a direção (true = ascending, false = descending).
     * 
     * Ordenação por outros campos requer ordenação em memória após buscar resultados.
     *
     * @param BaseBuilder $query Query builder com orderBy
     * @param array $params Referência aos parâmetros (modificado diretamente)
     * @param array $indexMatch Informações do índice usado (contém sort_key)
     * 
     * @return void
     * 
     * @since 1.0.0
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

        if (!$orderColumn) {
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

