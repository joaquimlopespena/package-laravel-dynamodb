<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException as AwsDynamoDbException;
use Aws\DynamoDb\Marshaler;
use Illuminate\Database\Connection as BaseConnection;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Grammar as DynamoDbGrammar;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Processor as DynamoDbProcessor;
use Joaquim\LaravelDynamoDb\Exceptions\BatchOperationException;
use Joaquim\LaravelDynamoDb\Exceptions\ConnectionException;
use Joaquim\LaravelDynamoDb\Exceptions\OperationException;
use Joaquim\LaravelDynamoDb\Exceptions\QueryException;
use Joaquim\LaravelDynamoDb\Exceptions\TableNotFoundException;
use Joaquim\LaravelDynamoDb\Exceptions\ValidationException;

class DynamoDbConnection extends BaseConnection
{
    /**
     * DynamoDB Client instance.
     */
    protected DynamoDbClient $dynamoDbClient;

    /**
     * Marshaler instance.
     */
    protected Marshaler $marshaler;

    /**
     * Create a new DynamoDB connection instance.
     */
    public function __construct(DynamoDbClient $client, array $config = [])
    {
        // Passar null como PDO (não usado no DynamoDB)
        // Connection base espera: $pdo, $database, $tablePrefix, $config
        // Para DynamoDB, usamos 'table' em vez de 'database'
        parent::__construct(
            fn () => null, // Closure que retorna null (PDO não usado)
            $config['table'] ?? $config['database'] ?? 'default',
            '',
            $config
        );

        $this->dynamoDbClient = $client;
        $this->marshaler = new Marshaler;
    }

    /**
     * Get the default query grammar instance.
     *
     * @return DynamoDbGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new DynamoDbGrammar($this);
    }

    /**
     * Get the default post processor instance.
     *
     * @return DynamoDbProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new DynamoDbProcessor;
    }

    /**
     * Get the DynamoDB Client instance.
     */
    public function getDynamoDbClient(): DynamoDbClient
    {
        return $this->dynamoDbClient;
    }

    /**
     * Get the Marshaler instance.
     */
    public function getMarshaler(): Marshaler
    {
        return $this->marshaler;
    }

    /**
     * Execute a select query.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @param  bool  $useReadPdo
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        // $query é array compilado pelo Grammar, não SQL string
        if (is_array($query)) {
            return $this->executeDynamoDbSelect($query);
        }

        throw new QueryException(
            message: 'DynamoDB does not support SQL queries',
            context: [
                'query' => $query,
                'bindings' => $bindings,
            ],
            suggestion: 'Use Eloquent or Query Builder methods instead of raw SQL queries.'
        );
    }

    /**
     * Execute a select query against DynamoDB.
     *
     * @return array
     */
    protected function executeDynamoDbSelect(array $compiled)
    {
        $operation = $compiled['operation'] ?? 'Scan';
        $params = $compiled['params'] ?? [];

        switch ($operation) {
            case 'BatchGetItem':
                // BatchGetItem: buscar múltiplos itens por chave primária
                $tableName = $params['TableName'];
                $keys = $params['Keys'] ?? [];
                $projectionExpression = $params['ProjectionExpression'] ?? null;
                $expressionAttributeNames = $params['ExpressionAttributeNames'] ?? null;

                $items = [];

                // DynamoDB BatchGetItem tem limite de 100 itens por requisição
                $chunks = array_chunk($keys, 100);

                foreach ($chunks as $chunk) {
                    $requestParams = [
                        'RequestItems' => [
                            $tableName => [
                                'Keys' => array_map(
                                    fn ($key) => $this->marshaler->marshalItem($key),
                                    $chunk
                                ),
                            ],
                        ],
                    ];

                    // Adicionar ProjectionExpression se houver
                    if ($projectionExpression) {
                        $requestParams['RequestItems'][$tableName]['ProjectionExpression'] = $projectionExpression;
                        if ($expressionAttributeNames) {
                            $requestParams['RequestItems'][$tableName]['ExpressionAttributeNames'] = $expressionAttributeNames;
                        }
                    }

                    try {
                        $result = $this->dynamoDbClient->batchGetItem($requestParams);
                    } catch (AwsDynamoDbException $e) {
                        throw new BatchOperationException(
                            message: "BatchGetItem failed for table '{$tableName}': ".$e->getMessage(),
                            previous: $e,
                            context: [
                                'table' => $tableName,
                                'keys_count' => count($chunk),
                                'aws_error_code' => $e->getAwsErrorCode(),
                            ]
                        );
                    } catch (\Throwable $e) {
                        throw new ConnectionException(
                            message: 'Failed to connect to DynamoDB for BatchGetItem: '.$e->getMessage(),
                            previous: $e,
                            context: ['table' => $tableName]
                        );
                    }

                    if (isset($result['Responses'][$tableName])) {
                        foreach ($result['Responses'][$tableName] as $item) {
                            $items[] = $this->marshaler->unmarshalItem($item);
                        }
                    }

                    // Se houver itens não processados, fazer requisições adicionais
                    if (isset($result['UnprocessedKeys'][$tableName])) {
                        // Em produção, você pode querer implementar retry logic aqui
                        // Por enquanto, vamos apenas logar
                        if (app()->bound('log')) {
                            app('log')->warning('DynamoDB BatchGetItem: Unprocessed keys', [
                                'table' => $tableName,
                                'count' => count($result['UnprocessedKeys'][$tableName]['Keys']),
                            ]);
                        }
                    }
                }
                break;

            case 'GetItem':
                // Marshal Key antes de enviar
                if (isset($params['Key']) && ! empty($params['Key'])) {
                    $params['Key'] = $this->marshaler->marshalItem($params['Key']);
                }

                try {
                    $result = $this->dynamoDbClient->getItem($params);
                } catch (AwsDynamoDbException $e) {
                    $tableName = $params['TableName'] ?? 'unknown';

                    // Check for specific AWS error codes
                    if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                        throw new TableNotFoundException(
                            tableName: $tableName,
                            previous: $e
                        );
                    }

                    throw new OperationException(
                        message: "GetItem failed for table '{$tableName}': ".$e->getMessage(),
                        previous: $e,
                        context: [
                            'table' => $tableName,
                            'key' => $params['Key'] ?? null,
                            'aws_error_code' => $e->getAwsErrorCode(),
                        ]
                    );
                } catch (\Throwable $e) {
                    throw new ConnectionException(
                        message: 'Failed to connect to DynamoDB for GetItem: '.$e->getMessage(),
                        previous: $e,
                        context: ['table' => $params['TableName'] ?? 'unknown']
                    );
                }

                $items = isset($result['Item'])
                    ? [$this->marshaler->unmarshalItem($result['Item'])]
                    : [];
                break;

            case 'Query':
                // Marshal ExpressionAttributeValues antes de enviar
                if (isset($params['ExpressionAttributeValues']) && ! empty($params['ExpressionAttributeValues'])) {
                    $params['ExpressionAttributeValues'] = $this->marshaler->marshalItem($params['ExpressionAttributeValues']);
                }

                // Debug: log se estiver usando índice
                if (app()->bound('log') && config('app.debug')) {
                    $indexInfo = isset($params['IndexName']) ? " usando índice '{$params['IndexName']}'" : ' usando Primary Key';
                    app('log')->debug("DynamoDB Query{$indexInfo}", [
                        'TableName' => $params['TableName'],
                        'KeyConditionExpression' => $params['KeyConditionExpression'] ?? null,
                    ]);
                }

                $hasLimit = isset($params['Limit']);
                $limit = $params['Limit'] ?? null;
                $hasFilterExpression = isset($params['FilterExpression']) && ! empty($params['FilterExpression']);

                // Otimização: Quando há FilterExpression e limit pequeno (≤50), processa em lotes menores
                // e para assim que encontrar resultados suficientes. Isso acelera o caso "não encontrado".
                $isOptimizedQuery = $hasFilterExpression && $hasLimit && $limit <= 50;

                if ($isOptimizedQuery) {
                    // Query otimizada: processa em lotes menores e para quando encontrar
                    $items = [];
                    $currentKey = null;
                    // Ajustado: para limits muito pequenos (≤5), processar até 10.000 itens antes de desistir
                    // Isso permite encontrar registros mesmo se houver muitos deletados antes
                    // Para limits maiores, processar até 3x o limit (comportamento anterior)
                    $maxScanned = $limit <= 5 ? 10000 : $limit * 3;
                    $totalScanned = 0;
                    $batchCount = 0;
                    $startTime = microtime(true);

                    do {
                        if ($currentKey) {
                            $params['ExclusiveStartKey'] = $currentKey;
                        }

                        // Usa lotes menores para acelerar quando não encontra
                        $params['Limit'] = min(20, $limit - count($items), $maxScanned - $totalScanned);

                        if ($params['Limit'] <= 0) {
                            break;
                        }

                        $result = $this->executeDynamoDbOperation(
                            fn () => $this->dynamoDbClient->query($params),
                            'Query',
                            [
                                'table' => $params['TableName'],
                                'index' => $params['IndexName'] ?? 'Primary',
                                'optimized' => true,
                            ]
                        );
                        $batchItems = array_map(
                            fn ($item) => $this->marshaler->unmarshalItem($item),
                            $result['Items'] ?? []
                        );

                        $items = array_merge($items, $batchItems);
                        $totalScanned += count($result['Items'] ?? []);
                        $batchCount++;
                        $currentKey = $result['LastEvaluatedKey'] ?? null;

                        // Para se encontrou resultados suficientes ou processou muito
                        if (count($items) >= $limit || $totalScanned >= $maxScanned || ! $currentKey) {
                            break;
                        }
                    } while (true);

                    // Log de debug para queries otimizadas
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    if (app()->bound('log') && config('app.debug')) {
                        app('log')->debug('DynamoDB Optimized Query Stats', [
                            'table' => $params['TableName'],
                            'index' => $params['IndexName'] ?? 'Primary',
                            'limit' => $limit,
                            'items_found' => count($items),
                            'items_scanned' => $totalScanned,
                            'batches' => $batchCount,
                            'duration_ms' => $duration,
                        ]);
                    }

                    // Limita aos primeiros $limit resultados
                    $items = array_slice($items, 0, $limit);
                } else {
                    // Query normal: comportamento padrão
                    $result = $this->executeDynamoDbOperation(
                        fn () => $this->dynamoDbClient->query($params),
                        'Query',
                        [
                            'table' => $params['TableName'],
                            'index' => $params['IndexName'] ?? 'Primary',
                        ]
                    );

                    // Paginação automática: se houver LastEvaluatedKey e não há Limit estrito,
                    // buscar mais páginas automaticamente (até 1MB ou limit definido)
                    $items = array_map(
                        fn ($item) => $this->marshaler->unmarshalItem($item),
                        $result['Items'] ?? []
                    );

                    // Se não há limit específico ou limit é maior que items retornados,
                    // e há LastEvaluatedKey, buscar mais páginas
                    $lastEvaluatedKey = $result['LastEvaluatedKey'] ?? null;

                    // OTIMIZAÇÃO: Para limits pequenos (≤50) SEM FilterExpression, não paginar se não encontrou nada
                    // Quando não há FilterExpression, as condições do índice já filtram tudo - se não encontrou
                    // na primeira página, não vai encontrar nas próximas (mesma partition+sort key).
                    // COM FilterExpression, pode haver registros nas próximas páginas que passem no filtro.
                    $shouldPaginate = $lastEvaluatedKey && (! $hasLimit || ($limit && count($items) < $limit));
                    $isSmallLimit = $hasLimit && $limit <= 50;
                    $noResultsInFirstPage = count($items) === 0;
                    $hasFilterExpression = isset($params['FilterExpression']) && ! empty($params['FilterExpression']);

                    if ($isSmallLimit && $noResultsInFirstPage && ! $hasFilterExpression) {
                        // Para limits pequenos SEM FilterExpression, se não encontrou nada na primeira página, não continuar
                        // (com FilterExpression pode haver registros filtrados nas próximas páginas)
                        $shouldPaginate = false;
                    }

                    // Apenas fazer paginação automática se explicitamente solicitado (sem limit estrito)
                    // ou se limit é maior que items retornados
                    if ($shouldPaginate) {
                        $allItems = $items;
                        $currentKey = $lastEvaluatedKey;

                        do {
                            $params['ExclusiveStartKey'] = $currentKey;
                            if ($hasLimit && $limit) {
                                $params['Limit'] = $limit - count($allItems);
                            }

                            $nextResult = $this->executeDynamoDbOperation(
                                fn () => $this->dynamoDbClient->query($params),
                                'Query',
                                [
                                    'table' => $params['TableName'],
                                    'index' => $params['IndexName'] ?? 'Primary',
                                    'pagination' => true,
                                ]
                            );
                            $nextItems = array_map(
                                fn ($item) => $this->marshaler->unmarshalItem($item),
                                $nextResult['Items'] ?? []
                            );

                            $allItems = array_merge($allItems, $nextItems);
                            $currentKey = $nextResult['LastEvaluatedKey'] ?? null;

                            // Limitar para evitar loops infinitos (máximo 10 páginas automáticas)
                            if (count($allItems) >= ($limit ?? 1000) || ! $currentKey) {
                                break;
                            }
                        } while ($currentKey && count($allItems) < ($limit ?? 1000));

                        $items = $allItems;
                    }
                }

                break;

            case 'Scan':
                // Marshal ExpressionAttributeValues antes de enviar
                if (isset($params['ExpressionAttributeValues']) && ! empty($params['ExpressionAttributeValues'])) {
                    $params['ExpressionAttributeValues'] = $this->marshaler->marshalItem($params['ExpressionAttributeValues']);
                }

                // Debug: log quando usar Scan (menos eficiente)
                if (app()->bound('log') && config('app.debug')) {
                    app('log')->warning('DynamoDB Scan (ineficiente - considerar criar índice)', [
                        'TableName' => $params['TableName'],
                        'FilterExpression' => $params['FilterExpression'] ?? null,
                    ]);
                }

                $result = $this->executeDynamoDbOperation(
                    fn () => $this->dynamoDbClient->scan($params),
                    'Scan',
                    ['table' => $params['TableName']]
                );

                // Se Select for COUNT, retornar apenas o count no formato correto
                if (isset($params['Select']) && $params['Select'] === 'COUNT') {
                    // Retornar no formato esperado pelo Processor
                    return [(object) ['Count' => $result['Count'] ?? 0]];
                }

                $items = array_map(
                    fn ($item) => $this->marshaler->unmarshalItem($item),
                    $result['Items'] ?? []
                );

                // Paginação automática para Scan (similar ao Query)
                $hasLimit = isset($params['Limit']);
                $limit = $params['Limit'] ?? null;
                $lastEvaluatedKey = $result['LastEvaluatedKey'] ?? null;

                // OTIMIZAÇÃO: Para Scan com limits pequenos (≤50) SEM FilterExpression, não paginar se vazio
                // Com FilterExpression, pode haver registros filtrados nas próximas páginas
                $shouldPaginate = $lastEvaluatedKey && (! $hasLimit || ($limit && count($items) < $limit));
                $isSmallLimit = $hasLimit && $limit <= 50;
                $noResultsInFirstPage = count($items) === 0;
                $hasFilterExpression = isset($params['FilterExpression']) && ! empty($params['FilterExpression']);

                if ($isSmallLimit && $noResultsInFirstPage && ! $hasFilterExpression) {
                    // Para limits pequenos SEM FilterExpression, se não encontrou nada, não continuar
                    $shouldPaginate = false;
                }

                if ($shouldPaginate) {
                    $allItems = $items;
                    $currentKey = $lastEvaluatedKey;

                    do {
                        $params['ExclusiveStartKey'] = $currentKey;
                        if ($hasLimit && $limit) {
                            $params['Limit'] = $limit - count($allItems);
                        }

                        $nextResult = $this->executeDynamoDbOperation(
                            fn () => $this->dynamoDbClient->scan($params),
                            'Scan',
                            ['table' => $params['TableName'], 'pagination' => true]
                        );
                        $nextItems = array_map(
                            fn ($item) => $this->marshaler->unmarshalItem($item),
                            $nextResult['Items'] ?? []
                        );

                        $allItems = array_merge($allItems, $nextItems);
                        $currentKey = $nextResult['LastEvaluatedKey'] ?? null;

                        // Limitar para evitar loops infinitos
                        if (count($allItems) >= ($limit ?? 1000) || ! $currentKey) {
                            break;
                        }
                    } while ($currentKey && count($allItems) < ($limit ?? 1000));

                    $items = $allItems;
                }

                break;

            default:
                throw new QueryException(
                    message: "Unknown DynamoDB operation: {$operation}",
                    context: [
                        'operation' => $operation,
                        'params' => $params,
                    ],
                    suggestion: 'Valid operations are: GetItem, BatchGetItem, Query, and Scan'
                );
        }

        // Converter para objetos (compatível com Laravel)
        return array_map(fn ($item) => (object) $item, $items);
    }

    /**
     * Cache de metadados de tabelas.
     */
    protected static array $tableMetadataCache = [];

    /**
     * Get table metadata (structure, indexes) with caching.
     *
     * @param  bool  $forceRefresh  Force refresh cache
     */
    public function getTableMetadata(string $tableName, bool $forceRefresh = false): array
    {
        $cacheKey = "dynamodb_table_{$tableName}_metadata";

        // Verificar cache
        if (! $forceRefresh && isset(self::$tableMetadataCache[$cacheKey])) {
            $cached = self::$tableMetadataCache[$cacheKey];

            // Cache válido por 1 hora
            if (isset($cached['cached_at']) && (time() - $cached['cached_at']) < 3600) {
                return $cached['data'];
            }
        }

        // Buscar metadados do DynamoDB
        try {
            $result = $this->executeDynamoDbOperation(
                fn () => $this->dynamoDbClient->describeTable(['TableName' => $tableName]),
                'DescribeTable',
                ['table' => $tableName]
            );

            $metadata = [
                'Table' => $result['Table'] ?? [],
                'TableName' => $tableName,
                'KeySchema' => $result['Table']['KeySchema'] ?? [],
                'AttributeDefinitions' => $result['Table']['AttributeDefinitions'] ?? [],
                'GlobalSecondaryIndexes' => $result['Table']['GlobalSecondaryIndexes'] ?? [],
                'LocalSecondaryIndexes' => $result['Table']['LocalSecondaryIndexes'] ?? [],
                'TableStatus' => $result['Table']['TableStatus'] ?? null,
                'ItemCount' => $result['Table']['ItemCount'] ?? 0,
            ];

            // Cachear metadados
            self::$tableMetadataCache[$cacheKey] = [
                'data' => $metadata,
                'cached_at' => time(),
            ];

            return $metadata;
        } catch (TableNotFoundException $e) {
            // Re-throw TableNotFoundException
            throw $e;
        } catch (\Throwable $e) {
            // Se erro ao buscar metadados, retornar cache anterior se existir
            if (isset(self::$tableMetadataCache[$cacheKey])) {
                return self::$tableMetadataCache[$cacheKey]['data'];
            }

            throw $e;
        }
    }

    /**
     * Clear table metadata cache.
     *
     * @param  string|null  $tableName  If null, clears all cache
     */
    public function clearMetadataCache(?string $tableName = null): void
    {
        if ($tableName) {
            unset(self::$tableMetadataCache["dynamodb_table_{$tableName}_metadata"]);
        } else {
            self::$tableMetadataCache = [];
        }
    }

    /**
     * Get the total count of items in a table using efficient COUNT scan.
     *
     * @param  array  $filterExpression  Optional filter expression
     */
    public function countItems(string $tableName, array $filterExpression = []): int
    {
        $params = [
            'TableName' => $tableName,
            'Select' => 'COUNT', // Apenas retornar contagem, não os itens
        ];

        // Adicionar FilterExpression se fornecido
        if (! empty($filterExpression)) {
            $params = array_merge($params, $filterExpression);
        }

        $totalCount = 0;
        $lastEvaluatedKey = null;

        do {
            if ($lastEvaluatedKey !== null) {
                $params['ExclusiveStartKey'] = $lastEvaluatedKey;
            }

            // Marshal ExpressionAttributeValues antes de enviar
            if (isset($params['ExpressionAttributeValues']) && ! empty($params['ExpressionAttributeValues'])) {
                $params['ExpressionAttributeValues'] = $this->marshaler->marshalItem($params['ExpressionAttributeValues']);
            }

            $result = $this->executeDynamoDbOperation(
                fn () => $this->dynamoDbClient->scan($params),
                'Scan',
                ['table' => $tableName, 'count_only' => true]
            );
            $totalCount += $result['Count'] ?? 0;
            $lastEvaluatedKey = $result['LastEvaluatedKey'] ?? null;
        } while ($lastEvaluatedKey !== null);

        return $totalCount;
    }

    /**
     * Count items using parallel scans for better performance on large tables.
     *
     * @param  int  $segments  Number of parallel segments (default: 4)
     * @param  array  $filterExpression  Optional filter expression
     */
    public function countItemsParallel(string $tableName, int $segments = 4, array $filterExpression = []): int
    {
        if ($segments < 1 || $segments > 100) {
            throw new \InvalidArgumentException('Segments must be between 1 and 100');
        }

        $params = [
            'TableName' => $tableName,
            'Select' => 'COUNT',
        ];

        // Adicionar FilterExpression se fornecido
        if (! empty($filterExpression)) {
            $params = array_merge($params, $filterExpression);
        }

        // Marshal ExpressionAttributeValues se houver
        if (isset($params['ExpressionAttributeValues']) && ! empty($params['ExpressionAttributeValues'])) {
            $params['ExpressionAttributeValues'] = $this->marshaler->marshalItem($params['ExpressionAttributeValues']);
        }

        $totalCount = 0;

        // Processar cada segmento sequencialmente (paralelização real requer threads/async PHP)
        // Para verdadeira paralelização, considere usar queue jobs ou processos separados
        for ($segment = 0; $segment < $segments; $segment++) {
            try {
                $segmentParams = array_merge($params, [
                    'Segment' => $segment,
                    'TotalSegments' => $segments,
                ]);

                $segmentCount = 0;
                $lastEvaluatedKey = null;

                do {
                    if ($lastEvaluatedKey !== null) {
                        $segmentParams['ExclusiveStartKey'] = $lastEvaluatedKey;
                    }

                    $segmentResult = $this->executeDynamoDbOperation(
                        fn () => $this->dynamoDbClient->scan($segmentParams),
                        'Scan',
                        ['table' => $tableName, 'segment' => $segment, 'parallel' => true]
                    );
                    $segmentCount += $segmentResult['Count'] ?? 0;
                    $lastEvaluatedKey = $segmentResult['LastEvaluatedKey'] ?? null;
                } while ($lastEvaluatedKey !== null);

                $totalCount += $segmentCount;
            } catch (\Exception $e) {
                // Log erro mas continua com outros segmentos
                if (app()->bound('log')) {
                    app('log')->warning("DynamoDB parallel scan segment {$segment} failed", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $totalCount;
    }

    /**
     * Execute an insert statement.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return bool
     */
    public function insert($query, $bindings = [])
    {
        // $query é array compilado pelo Grammar
        if (is_array($query)) {
            $this->executeDynamoDbPutItem($query);

            return true;
        }

        throw new QueryException(
            message: 'Invalid insert query format',
            context: ['query' => $query, 'bindings' => $bindings],
            suggestion: 'Use the Query Builder or Eloquent for insert operations'
        );
    }

    /**
     * Execute PutItem operation.
     *
     * @return void
     */
    protected function executeDynamoDbPutItem(array $compiled)
    {
        $params = $compiled['params'] ?? $compiled;
        $item = $params['Item'] ?? $params;
        $tableName = $params['TableName'] ?? $this->getConfig('table');

        // Validar que o Item não está vazio
        if (empty($item)) {
            throw new ValidationException(
                message: "Cannot insert empty item into table '{$tableName}'",
                context: ['table' => $tableName],
                suggestion: 'Ensure the item contains at least the required key attributes'
            );
        }

        // Log para debug
        if (app()->bound('log') && config('app.debug')) {
            app('log')->debug('DynamoDB PutItem', [
                'table' => $tableName,
                'item_keys' => array_keys($item),
                'has_id' => isset($item['id']),
                'item' => $item,
            ]);
        }

        $this->executeDynamoDbOperation(
            fn () => $this->dynamoDbClient->putItem([
                'TableName' => $tableName,
                'Item' => $this->marshaler->marshalItem($item),
            ]),
            'PutItem',
            ['table' => $tableName, 'item_keys' => array_keys($item)]
        );
    }

    /**
     * Execute an update statement.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return int
     */
    public function update($query, $bindings = [])
    {
        // $query é array compilado pelo Grammar
        if (is_array($query)) {
            $this->executeDynamoDbUpdateItem($query);

            return 1; // Retornar número de linhas afetadas
        }

        throw new QueryException(
            message: 'Invalid update query format',
            context: ['query' => $query, 'bindings' => $bindings],
            suggestion: 'Use the Query Builder or Eloquent for update operations'
        );
    }

    /**
     * Execute UpdateItem operation.
     *
     * @return void
     */
    protected function executeDynamoDbUpdateItem(array $compiled)
    {
        $params = $compiled['params'] ?? $compiled;
        $tableName = $params['TableName'] ?? $this->getConfig('table');

        $this->executeDynamoDbOperation(
            fn () => $this->dynamoDbClient->updateItem([
                'TableName' => $tableName,
                'Key' => $this->marshaler->marshalItem($params['Key']),
                'UpdateExpression' => $params['UpdateExpression'] ?? '',
                'ExpressionAttributeValues' => isset($params['ExpressionAttributeValues'])
                    ? $this->marshaler->marshalItem($params['ExpressionAttributeValues'])
                    : [],
                'ExpressionAttributeNames' => $params['ExpressionAttributeNames'] ?? [],
            ]),
            'UpdateItem',
            ['table' => $tableName, 'key' => $params['Key'] ?? null]
        );
    }

    /**
     * Execute a delete statement.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return int
     */
    public function delete($query, $bindings = [])
    {
        // $query é array compilado pelo Grammar
        if (is_array($query)) {
            $this->executeDynamoDbDeleteItem($query);

            return 1; // Retornar número de linhas afetadas
        }

        throw new QueryException(
            message: 'Invalid delete query format',
            context: ['query' => $query, 'bindings' => $bindings],
            suggestion: 'Use the Query Builder or Eloquent for delete operations'
        );
    }

    /**
     * Execute DeleteItem operation.
     *
     * @return void
     */
    protected function executeDynamoDbDeleteItem(array $compiled)
    {
        $params = $compiled['params'] ?? $compiled;
        $tableName = $params['TableName'] ?? $this->getConfig('table');

        $this->executeDynamoDbOperation(
            fn () => $this->dynamoDbClient->deleteItem([
                'TableName' => $tableName,
                'Key' => $this->marshaler->marshalItem($params['Key']),
            ]),
            'DeleteItem',
            ['table' => $tableName, 'key' => $params['Key'] ?? null]
        );
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    public function query()
    {
        return new \Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder($this);
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function getTableName()
    {
        return $this->getConfig('table');
    }

    /**
     * Execute a DynamoDB operation with exception handling.
     *
     * @return mixed
     */
    protected function executeDynamoDbOperation(callable $operation, string $operationType, array $context = [])
    {
        try {
            return $operation();
        } catch (AwsDynamoDbException $e) {
            $awsErrorCode = $e->getAwsErrorCode();
            $tableName = $context['table'] ?? 'unknown';

            // Handle specific AWS error codes
            if ($awsErrorCode === 'ResourceNotFoundException') {
                throw new TableNotFoundException(
                    tableName: $tableName,
                    previous: $e,
                    context: $context
                );
            }

            if ($awsErrorCode === 'ProvisionedThroughputExceededException') {
                throw new OperationException(
                    message: "Throughput exceeded for table '{$tableName}'",
                    previous: $e,
                    context: array_merge($context, [
                        'aws_error_code' => $awsErrorCode,
                        'operation' => $operationType,
                    ]),
                    suggestion: 'Implement retry logic with exponential backoff as immediate mitigation. For long-term solutions, consider increasing provisioned capacity or using on-demand billing mode.'
                );
            }

            if ($awsErrorCode === 'RequestLimitExceeded') {
                throw new OperationException(
                    message: 'Request limit exceeded for DynamoDB operation',
                    previous: $e,
                    context: array_merge($context, [
                        'aws_error_code' => $awsErrorCode,
                        'operation' => $operationType,
                    ])
                );
            }

            // Generic AWS DynamoDB exception
            throw new OperationException(
                message: "{$operationType} failed: ".$e->getMessage(),
                previous: $e,
                context: array_merge($context, [
                    'aws_error_code' => $awsErrorCode,
                    'operation' => $operationType,
                ])
            );
        } catch (\Throwable $e) {
            throw new ConnectionException(
                message: "Failed to connect to DynamoDB for {$operationType}: ".$e->getMessage(),
                previous: $e,
                context: $context
            );
        }
    }
}
