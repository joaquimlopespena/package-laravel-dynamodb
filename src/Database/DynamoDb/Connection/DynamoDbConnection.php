<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection;

use Illuminate\Database\Connection as BaseConnection;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Grammar as DynamoDbGrammar;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Processor as DynamoDbProcessor;

class DynamoDbConnection extends BaseConnection
{
    /**
     * DynamoDB Client instance.
     *
     * @var DynamoDbClient
     */
    protected DynamoDbClient $dynamoDbClient;

    /**
     * Marshaler instance.
     *
     * @var Marshaler
     */
    protected Marshaler $marshaler;

    /**
     * Create a new DynamoDB connection instance.
     *
     * @param DynamoDbClient $client
     * @param array $config
     */
    public function __construct(DynamoDbClient $client, array $config = [])
    {
        // Passar null como PDO (não usado no DynamoDB)
        // Connection base espera: $pdo, $database, $tablePrefix, $config
        // Para DynamoDB, usamos 'table' em vez de 'database'
        parent::__construct(
            fn() => null, // Closure que retorna null (PDO não usado)
            $config['table'] ?? $config['database'] ?? 'default',
            '',
            $config
        );

        $this->dynamoDbClient = $client;
        $this->marshaler = new Marshaler();
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
        return new DynamoDbProcessor();
    }

    /**
     * Get the DynamoDB Client instance.
     *
     * @return DynamoDbClient
     */
    public function getDynamoDbClient(): DynamoDbClient
    {
        return $this->dynamoDbClient;
    }

    /**
     * Get the Marshaler instance.
     *
     * @return Marshaler
     */
    public function getMarshaler(): Marshaler
    {
        return $this->marshaler;
    }

    /**
     * Execute a select query.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        // $query é array compilado pelo Grammar, não SQL string
        if (is_array($query)) {
            return $this->executeDynamoDbSelect($query);
        }

        throw new \RuntimeException('DynamoDB does not support SQL queries');
    }

    /**
     * Execute a select query against DynamoDB.
     *
     * @param array $compiled
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
                                    fn($key) => $this->marshaler->marshalItem($key),
                                    $chunk
                                )
                            ]
                        ]
                    ];
                    
                    // Adicionar ProjectionExpression se houver
                    if ($projectionExpression) {
                        $requestParams['RequestItems'][$tableName]['ProjectionExpression'] = $projectionExpression;
                        if ($expressionAttributeNames) {
                            $requestParams['RequestItems'][$tableName]['ExpressionAttributeNames'] = $expressionAttributeNames;
                        }
                    }
                    
                    $result = $this->dynamoDbClient->batchGetItem($requestParams);
                    
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
                                'count' => count($result['UnprocessedKeys'][$tableName]['Keys'])
                            ]);
                        }
                    }
                }
                break;

            case 'GetItem':
                // Marshal Key antes de enviar
                if (isset($params['Key']) && !empty($params['Key'])) {
                    $params['Key'] = $this->marshaler->marshalItem($params['Key']);
                }

                $result = $this->dynamoDbClient->getItem($params);
                $items = isset($result['Item'])
                    ? [$this->marshaler->unmarshalItem($result['Item'])]
                    : [];
                break;

            case 'Query':
                // Marshal ExpressionAttributeValues antes de enviar
                if (isset($params['ExpressionAttributeValues']) && !empty($params['ExpressionAttributeValues'])) {
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
                $hasFilterExpression = isset($params['FilterExpression']) && !empty($params['FilterExpression']);
                
                // Otimização: Quando há FilterExpression e limit pequeno (≤50), processa em lotes menores
                // e para assim que encontrar resultados suficientes. Isso acelera o caso "não encontrado".
                $isOptimizedQuery = $hasFilterExpression && $hasLimit && $limit <= 50;
                
                if ($isOptimizedQuery) {
                    // Query otimizada: processa em lotes menores e para quando encontrar
                    $items = [];
                    $currentKey = null;
                    $maxScanned = $limit * 3; // Processa até 3x o limit antes de desistir
                    $totalScanned = 0;
                    
                    do {
                        if ($currentKey) {
                            $params['ExclusiveStartKey'] = $currentKey;
                        }
                        
                        // Usa lotes menores para acelerar quando não encontra
                        $params['Limit'] = min(20, $limit - count($items), $maxScanned - $totalScanned);
                        
                        if ($params['Limit'] <= 0) {
                            break;
                        }
                        
                        $result = $this->dynamoDbClient->query($params);
                        $batchItems = array_map(
                            fn($item) => $this->marshaler->unmarshalItem($item),
                            $result['Items'] ?? []
                        );
                        
                        $items = array_merge($items, $batchItems);
                        $totalScanned += count($result['Items'] ?? []);
                        $currentKey = $result['LastEvaluatedKey'] ?? null;
                        
                        // Para se encontrou resultados suficientes ou processou muito
                        if (count($items) >= $limit || $totalScanned >= $maxScanned || !$currentKey) {
                            break;
                        }
                    } while (true);
                    
                    // Limita aos primeiros $limit resultados
                    $items = array_slice($items, 0, $limit);
                } else {
                    // Query normal: comportamento padrão
                    $result = $this->dynamoDbClient->query($params);

                    // Paginação automática: se houver LastEvaluatedKey e não há Limit estrito,
                    // buscar mais páginas automaticamente (até 1MB ou limit definido)
                    $items = array_map(
                        fn($item) => $this->marshaler->unmarshalItem($item),
                        $result['Items'] ?? []
                    );

                    // Se não há limit específico ou limit é maior que items retornados,
                    // e há LastEvaluatedKey, buscar mais páginas
                    $lastEvaluatedKey = $result['LastEvaluatedKey'] ?? null;

                    // Apenas fazer paginação automática se explicitamente solicitado (sem limit estrito)
                    // ou se limit é maior que items retornados
                    if ($lastEvaluatedKey && (!$hasLimit || ($limit && count($items) < $limit))) {
                        $allItems = $items;
                        $currentKey = $lastEvaluatedKey;

                        do {
                            $params['ExclusiveStartKey'] = $currentKey;
                            if ($hasLimit && $limit) {
                                $params['Limit'] = $limit - count($allItems);
                            }

                            $nextResult = $this->dynamoDbClient->query($params);
                            $nextItems = array_map(
                                fn($item) => $this->marshaler->unmarshalItem($item),
                                $nextResult['Items'] ?? []
                            );

                            $allItems = array_merge($allItems, $nextItems);
                            $currentKey = $nextResult['LastEvaluatedKey'] ?? null;

                            // Limitar para evitar loops infinitos (máximo 10 páginas automáticas)
                            if (count($allItems) >= ($limit ?? 1000) || !$currentKey) {
                                break;
                            }
                        } while ($currentKey && count($allItems) < ($limit ?? 1000));

                        $items = $allItems;
                    }
                }

                break;

            case 'Scan':
                // Marshal ExpressionAttributeValues antes de enviar
                if (isset($params['ExpressionAttributeValues']) && !empty($params['ExpressionAttributeValues'])) {
                    $params['ExpressionAttributeValues'] = $this->marshaler->marshalItem($params['ExpressionAttributeValues']);
                }

                // Debug: log quando usar Scan (menos eficiente)
                if (app()->bound('log') && config('app.debug')) {
                    app('log')->warning('DynamoDB Scan (ineficiente - considerar criar índice)', [
                        'TableName' => $params['TableName'],
                        'FilterExpression' => $params['FilterExpression'] ?? null,
                    ]);
                }

                $result = $this->dynamoDbClient->scan($params);

                // Se Select for COUNT, retornar apenas o count no formato correto
                if (isset($params['Select']) && $params['Select'] === 'COUNT') {
                    // Retornar no formato esperado pelo Processor
                    return [(object) ['Count' => $result['Count'] ?? 0]];
                }

                $items = array_map(
                    fn($item) => $this->marshaler->unmarshalItem($item),
                    $result['Items'] ?? []
                );

                // Paginação automática para Scan (similar ao Query)
                $hasLimit = isset($params['Limit']);
                $limit = $params['Limit'] ?? null;
                $lastEvaluatedKey = $result['LastEvaluatedKey'] ?? null;

                if ($lastEvaluatedKey && (!$hasLimit || ($limit && count($items) < $limit))) {
                    $allItems = $items;
                    $currentKey = $lastEvaluatedKey;

                    do {
                        $params['ExclusiveStartKey'] = $currentKey;
                        if ($hasLimit && $limit) {
                            $params['Limit'] = $limit - count($allItems);
                        }

                        $nextResult = $this->dynamoDbClient->scan($params);
                        $nextItems = array_map(
                            fn($item) => $this->marshaler->unmarshalItem($item),
                            $nextResult['Items'] ?? []
                        );

                        $allItems = array_merge($allItems, $nextItems);
                        $currentKey = $nextResult['LastEvaluatedKey'] ?? null;

                        // Limitar para evitar loops infinitos
                        if (count($allItems) >= ($limit ?? 1000) || !$currentKey) {
                            break;
                        }
                    } while ($currentKey && count($allItems) < ($limit ?? 1000));

                    $items = $allItems;
                }

                break;

            default:
                throw new \RuntimeException("Unknown operation: {$operation}");
        }

        // Converter para objetos (compatível com Laravel)
        return array_map(fn($item) => (object) $item, $items);
    }

    /**
     * Cache de metadados de tabelas.
     *
     * @var array
     */
    protected static array $tableMetadataCache = [];

    /**
     * Get table metadata (structure, indexes) with caching.
     *
     * @param string $tableName
     * @param bool $forceRefresh Force refresh cache
     * @return array
     */
    public function getTableMetadata(string $tableName, bool $forceRefresh = false): array
    {
        $cacheKey = "dynamodb_table_{$tableName}_metadata";

        // Verificar cache
        if (!$forceRefresh && isset(self::$tableMetadataCache[$cacheKey])) {
            $cached = self::$tableMetadataCache[$cacheKey];

            // Cache válido por 1 hora
            if (isset($cached['cached_at']) && (time() - $cached['cached_at']) < 3600) {
                return $cached['data'];
            }
        }

        // Buscar metadados do DynamoDB
        try {
            $result = $this->dynamoDbClient->describeTable([
                'TableName' => $tableName,
            ]);

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
        } catch (\Exception $e) {
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
     * @param string|null $tableName If null, clears all cache
     * @return void
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
     * @param string $tableName
     * @param array $filterExpression Optional filter expression
     * @return int
     */
    public function countItems(string $tableName, array $filterExpression = []): int
    {
        $params = [
            'TableName' => $tableName,
            'Select' => 'COUNT', // Apenas retornar contagem, não os itens
        ];

        // Adicionar FilterExpression se fornecido
        if (!empty($filterExpression)) {
            $params = array_merge($params, $filterExpression);
        }

        $totalCount = 0;
        $lastEvaluatedKey = null;

        do {
            if ($lastEvaluatedKey !== null) {
                $params['ExclusiveStartKey'] = $lastEvaluatedKey;
            }

            // Marshal ExpressionAttributeValues antes de enviar
            if (isset($params['ExpressionAttributeValues']) && !empty($params['ExpressionAttributeValues'])) {
                $params['ExpressionAttributeValues'] = $this->marshaler->marshalItem($params['ExpressionAttributeValues']);
            }

            $result = $this->dynamoDbClient->scan($params);
            $totalCount += $result['Count'] ?? 0;
            $lastEvaluatedKey = $result['LastEvaluatedKey'] ?? null;
        } while ($lastEvaluatedKey !== null);

        return $totalCount;
    }

    /**
     * Count items using parallel scans for better performance on large tables.
     *
     * @param string $tableName
     * @param int $segments Number of parallel segments (default: 4)
     * @param array $filterExpression Optional filter expression
     * @return int
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
        if (!empty($filterExpression)) {
            $params = array_merge($params, $filterExpression);
        }

        // Marshal ExpressionAttributeValues se houver
        if (isset($params['ExpressionAttributeValues']) && !empty($params['ExpressionAttributeValues'])) {
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

                    $segmentResult = $this->dynamoDbClient->scan($segmentParams);
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
     * @param string $query
     * @param array $bindings
     * @return bool
     */
    public function insert($query, $bindings = [])
    {
        // $query é array compilado pelo Grammar
        if (is_array($query)) {
            $this->executeDynamoDbPutItem($query);
            return true;
        }

        throw new \RuntimeException('Invalid insert query format');
    }

    /**
     * Execute PutItem operation.
     *
     * @param array $compiled
     * @return void
     */
    protected function executeDynamoDbPutItem(array $compiled)
    {
        $params = $compiled['params'] ?? $compiled;
        $item = $params['Item'] ?? $params;
        $tableName = $params['TableName'] ?? $this->getConfig('table');

        // Validar que o Item não está vazio
        if (empty($item)) {
            throw new \RuntimeException("Cannot insert empty item into table '{$tableName}'");
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

        $this->dynamoDbClient->putItem([
            'TableName' => $tableName,
            'Item' => $this->marshaler->marshalItem($item),
        ]);
    }

    /**
     * Execute an update statement.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function update($query, $bindings = [])
    {
        // $query é array compilado pelo Grammar
        if (is_array($query)) {
            $this->executeDynamoDbUpdateItem($query);
            return 1; // Retornar número de linhas afetadas
        }

        throw new \RuntimeException('Invalid update query format');
    }

    /**
     * Execute UpdateItem operation.
     *
     * @param array $compiled
     * @return void
     */
    protected function executeDynamoDbUpdateItem(array $compiled)
    {
        $params = $compiled['params'] ?? $compiled;

        $this->dynamoDbClient->updateItem([
            'TableName' => $params['TableName'] ?? $this->getConfig('table'),
            'Key' => $this->marshaler->marshalItem($params['Key']),
            'UpdateExpression' => $params['UpdateExpression'] ?? '',
            'ExpressionAttributeValues' => isset($params['ExpressionAttributeValues'])
                ? $this->marshaler->marshalItem($params['ExpressionAttributeValues'])
                : [],
            'ExpressionAttributeNames' => $params['ExpressionAttributeNames'] ?? [],
        ]);
    }

    /**
     * Execute a delete statement.
     *
     * @param string $query
     * @param array $bindings
     * @return int
     */
    public function delete($query, $bindings = [])
    {
        // $query é array compilado pelo Grammar
        if (is_array($query)) {
            $this->executeDynamoDbDeleteItem($query);
            return 1; // Retornar número de linhas afetadas
        }

        throw new \RuntimeException('Invalid delete query format');
    }

    /**
     * Execute DeleteItem operation.
     *
     * @param array $compiled
     * @return void
     */
    protected function executeDynamoDbDeleteItem(array $compiled)
    {
        $params = $compiled['params'] ?? $compiled;

        $this->dynamoDbClient->deleteItem([
            'TableName' => $params['TableName'] ?? $this->getConfig('table'),
            'Key' => $this->marshaler->marshalItem($params['Key']),
        ]);
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
}
