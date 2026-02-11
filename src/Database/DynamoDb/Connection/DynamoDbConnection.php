<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection;

use Illuminate\Database\Connection as BaseConnection;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Grammar as DynamoDbGrammar;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Processor as DynamoDbProcessor;

/**
 * DynamoDB Connection Class.
 * 
 * Esta classe gerencia a conexão com o DynamoDB e executa operações de banco de dados.
 * Substitui a conexão PDO padrão do Laravel com operações específicas do DynamoDB.
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection
 * @since 1.0.0
 */
class DynamoDbConnection extends BaseConnection
{
    /**
     * DynamoDB Client instance.
     * 
     * Instância do cliente AWS SDK para comunicação com o DynamoDB.
     *
     * @var DynamoDbClient
     */
    protected DynamoDbClient $dynamoDbClient;

    /**
     * Marshaler instance.
     * 
     * Responsável pela conversão de tipos PHP para tipos DynamoDB e vice-versa.
     *
     * @var Marshaler
     */
    protected Marshaler $marshaler;

    /**
     * Create a new DynamoDB connection instance.
     * 
     * Inicializa a conexão com o DynamoDB, configurando o cliente AWS SDK
     * e o marshaler para conversão de tipos de dados.
     *
     * @param DynamoDbClient $client Cliente AWS DynamoDB configurado
     * @param array $config Configurações da conexão incluindo table, region, credentials
     * 
     * @example
     * $client = new DynamoDbClient([
     *     'region' => 'us-east-1',
     *     'version' => 'latest'
     * ]);
     * $connection = new DynamoDbConnection($client, [
     *     'table' => 'users',
     *     'region' => 'us-east-1'
     * ]);
     * 
     * @since 1.0.0
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
     * Retorna a instância do Grammar customizado para DynamoDB que compila
     * queries do Laravel em operações DynamoDB (GetItem, Query, Scan).
     *
     * @return DynamoDbGrammar Instância do grammar DynamoDB
     * 
     * @since 1.0.0
     */
    protected function getDefaultQueryGrammar()
    {
        return new DynamoDbGrammar($this);
    }

    /**
     * Get the default post processor instance.
     * 
     * Retorna o processador que converte resultados DynamoDB para
     * o formato esperado pelo Laravel.
     *
     * @return DynamoDbProcessor Instância do processor DynamoDB
     * 
     * @since 1.0.0
     */
    protected function getDefaultPostProcessor()
    {
        return new DynamoDbProcessor();
    }

    /**
     * Get the DynamoDB Client instance.
     * 
     * Retorna o cliente AWS SDK configurado para realizar operações
     * diretamente no DynamoDB.
     *
     * @return DynamoDbClient Cliente AWS DynamoDB
     * 
     * @example
     * $client = $connection->getDynamoDbClient();
     * $result = $client->describeTable(['TableName' => 'users']);
     * 
     * @since 1.0.0
     */
    public function getDynamoDbClient(): DynamoDbClient
    {
        return $this->dynamoDbClient;
    }

    /**
     * Get the Marshaler instance.
     * 
     * Retorna o marshaler usado para conversão automática entre tipos PHP
     * e tipos DynamoDB (String, Number, Binary, List, Map, etc).
     *
     * @return Marshaler Instância do marshaler AWS SDK
     * 
     * @example
     * $marshaler = $connection->getMarshaler();
     * $item = $marshaler->marshalItem(['id' => 1, 'name' => 'John']);
     * 
     * @since 1.0.0
     */
    public function getMarshaler(): Marshaler
    {
        return $this->marshaler;
    }

    /**
     * Execute a select query.
     * 
     * Executa uma query de seleção compilada pelo Grammar. O parâmetro $query
     * é um array compilado (não uma string SQL) contendo a operação DynamoDB
     * (GetItem, Query, Scan, BatchGetItem) e seus parâmetros.
     *
     * @param string|array $query Array compilado com operação e parâmetros DynamoDB
     * @param array $bindings Não utilizado (mantido por compatibilidade com Laravel)
     * @param bool $useReadPdo Não utilizado (mantido por compatibilidade com Laravel)
     * 
     * @return array Array de objetos com os resultados da query
     * 
     * @throws \RuntimeException Se $query não for um array (DynamoDB não suporta SQL)
     * 
     * @example
     * // Executado internamente pelo Query Builder
     * $compiled = ['operation' => 'Query', 'params' => ['TableName' => 'users', ...]];
     * $results = $connection->select($compiled);
     * 
     * @since 1.0.0
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
     * Executa a operação DynamoDB apropriada (GetItem, Query, Scan, BatchGetItem)
     * baseada no array compilado. Gerencia paginação automática e conversão de resultados.
     *
     * @param array $compiled Array contendo 'operation' e 'params' da query compilada
     * 
     * @return array Array de objetos com os itens retornados pelo DynamoDB
     * 
     * @throws \RuntimeException Se a operação for desconhecida
     * 
     * @example
     * $compiled = [
     *     'operation' => 'Query',
     *     'params' => [
     *         'TableName' => 'users',
     *         'KeyConditionExpression' => '#pk = :pk',
     *         'ExpressionAttributeNames' => ['#pk' => 'id'],
     *         'ExpressionAttributeValues' => [':pk' => 'user123']
     *     ]
     * ];
     * $results = $this->executeDynamoDbSelect($compiled);
     * 
     * @since 1.0.0
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

                        $result = $this->dynamoDbClient->query($params);
                        $batchItems = array_map(
                            fn($item) => $this->marshaler->unmarshalItem($item),
                            $result['Items'] ?? []
                        );

                        $items = array_merge($items, $batchItems);
                        $totalScanned += count($result['Items'] ?? []);
                        $batchCount++;
                        $currentKey = $result['LastEvaluatedKey'] ?? null;

                        // Para se encontrou resultados suficientes ou processou muito
                        if (count($items) >= $limit || $totalScanned >= $maxScanned || !$currentKey) {
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

                    // OTIMIZAÇÃO: Para limits pequenos (≤50) SEM FilterExpression, não paginar se não encontrou nada
                    // Quando não há FilterExpression, as condições do índice já filtram tudo - se não encontrou
                    // na primeira página, não vai encontrar nas próximas (mesma partition+sort key).
                    // COM FilterExpression, pode haver registros nas próximas páginas que passem no filtro.
                    $shouldPaginate = $lastEvaluatedKey && (!$hasLimit || ($limit && count($items) < $limit));
                    $isSmallLimit = $hasLimit && $limit <= 50;
                    $noResultsInFirstPage = count($items) === 0;
                    $hasFilterExpression = isset($params['FilterExpression']) && !empty($params['FilterExpression']);
                    
                    if ($isSmallLimit && $noResultsInFirstPage && !$hasFilterExpression) {
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

                // OTIMIZAÇÃO: Para Scan com limits pequenos (≤50) SEM FilterExpression, não paginar se vazio
                // Com FilterExpression, pode haver registros filtrados nas próximas páginas
                $shouldPaginate = $lastEvaluatedKey && (!$hasLimit || ($limit && count($items) < $limit));
                $isSmallLimit = $hasLimit && $limit <= 50;
                $noResultsInFirstPage = count($items) === 0;
                $hasFilterExpression = isset($params['FilterExpression']) && !empty($params['FilterExpression']);
                
                if ($isSmallLimit && $noResultsInFirstPage && !$hasFilterExpression) {
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
     * Armazena metadados (estrutura, índices, keys) de tabelas DynamoDB
     * para evitar múltiplas chamadas describeTable.
     *
     * @var array
     */
    protected static array $tableMetadataCache = [];

    /**
     * Get table metadata (structure, indexes) with caching.
     * 
     * Obtém metadados completos da tabela DynamoDB incluindo KeySchema,
     * AttributeDefinitions, GSI, LSI e status. Usa cache de 1 hora para
     * melhorar performance.
     *
     * @param string $tableName Nome da tabela DynamoDB
     * @param bool $forceRefresh Force atualização do cache
     * 
     * @return array Array com metadados incluindo Table, KeySchema, AttributeDefinitions, GlobalSecondaryIndexes, LocalSecondaryIndexes
     * 
     * @throws \Exception Se não conseguir obter metadados e não houver cache
     * 
     * @example
     * $metadata = $connection->getTableMetadata('users');
     * $partitionKey = $metadata['KeySchema'][0]['AttributeName'];
     * $gsiIndexes = $metadata['GlobalSecondaryIndexes'];
     * 
     * @since 1.0.0
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
     * Limpa o cache de metadados de tabela. Útil quando a estrutura
     * da tabela é modificada (índices adicionados/removidos).
     *
     * @param string|null $tableName Nome da tabela para limpar cache. Se null, limpa todo o cache
     * 
     * @return void
     * 
     * @example
     * // Limpar cache de uma tabela específica
     * $connection->clearMetadataCache('users');
     * 
     * // Limpar todo o cache
     * $connection->clearMetadataCache();
     * 
     * @since 1.0.0
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
     * Conta o total de itens na tabela usando Scan com Select=COUNT,
     * o que é mais eficiente que retornar todos os itens. Gerencia
     * paginação automática para contar toda a tabela.
     *
     * @param string $tableName Nome da tabela DynamoDB
     * @param array $filterExpression Expressão de filtro opcional (FilterExpression, ExpressionAttributeNames, ExpressionAttributeValues)
     * 
     * @return int Total de itens na tabela
     * 
     * @example
     * // Contar todos os itens
     * $total = $connection->countItems('users');
     * 
     * // Contar com filtro
     * $activeCount = $connection->countItems('users', [
     *     'FilterExpression' => 'status = :status',
     *     'ExpressionAttributeValues' => [':status' => 'active']
     * ]);
     * 
     * @since 1.0.0
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
     * Conta itens usando Parallel Scan (divide a tabela em segmentos) para
     * melhor performance em tabelas grandes. Cada segmento é processado
     * sequencialmente (paralelização real requer processos separados).
     *
     * @param string $tableName Nome da tabela DynamoDB
     * @param int $segments Número de segmentos paralelos (1-100, padrão: 4)
     * @param array $filterExpression Expressão de filtro opcional
     * 
     * @return int Total de itens contados em todos os segmentos
     * 
     * @throws \InvalidArgumentException Se segments não estiver entre 1 e 100
     * 
     * @example
     * // Contar com 8 segmentos para melhor performance
     * $total = $connection->countItemsParallel('users', 8);
     * 
     * // Com filtro
     * $activeCount = $connection->countItemsParallel('users', 4, [
     *     'FilterExpression' => 'status = :status',
     *     'ExpressionAttributeValues' => [':status' => 'active']
     * ]);
     * 
     * @since 1.0.0
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
     * Executa uma operação PutItem no DynamoDB. O parâmetro $query é um array
     * compilado pelo Grammar contendo TableName e Item.
     *
     * @param string|array $query Array compilado com parâmetros do PutItem
     * @param array $bindings Não utilizado (mantido por compatibilidade com Laravel)
     * 
     * @return bool Sempre retorna true em caso de sucesso
     * 
     * @throws \RuntimeException Se $query não for um array válido
     * 
     * @example
     * // Executado internamente pelo Query Builder
     * $compiled = [
     *     'params' => [
     *         'TableName' => 'users',
     *         'Item' => ['id' => 'user123', 'name' => 'John', 'email' => 'john@example.com']
     *     ]
     * ];
     * $connection->insert($compiled);
     * 
     * @since 1.0.0
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
     * Insere ou substitui um item completo na tabela DynamoDB.
     * Realiza marshaling automático dos dados antes de enviar.
     *
     * @param array $compiled Array compilado contendo params com TableName e Item
     * 
     * @return void
     * 
     * @throws \RuntimeException Se Item estiver vazio
     * 
     * @since 1.0.0
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
     * Executa uma operação UpdateItem no DynamoDB, atualizando atributos
     * específicos de um item existente.
     *
     * @param string|array $query Array compilado com parâmetros do UpdateItem
     * @param array $bindings Não utilizado (mantido por compatibilidade com Laravel)
     * 
     * @return int Número de linhas afetadas (sempre 1 no DynamoDB)
     * 
     * @throws \RuntimeException Se $query não for um array válido
     * 
     * @example
     * // Executado internamente pelo Query Builder
     * $compiled = [
     *     'params' => [
     *         'TableName' => 'users',
     *         'Key' => ['id' => 'user123'],
     *         'UpdateExpression' => 'SET #name = :name',
     *         'ExpressionAttributeNames' => ['#name' => 'name'],
     *         'ExpressionAttributeValues' => [':name' => 'John Updated']
     *     ]
     * ];
     * $connection->update($compiled);
     * 
     * @since 1.0.0
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
     * Atualiza atributos específicos de um item no DynamoDB usando
     * UpdateExpression. Realiza marshaling automático dos valores.
     *
     * @param array $compiled Array compilado contendo params com TableName, Key, UpdateExpression, etc
     * 
     * @return void
     * 
     * @since 1.0.0
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
     * Executa uma operação DeleteItem no DynamoDB, removendo um item
     * específico identificado por sua chave primária.
     *
     * @param string|array $query Array compilado com parâmetros do DeleteItem
     * @param array $bindings Não utilizado (mantido por compatibilidade com Laravel)
     * 
     * @return int Número de linhas afetadas (sempre 1 no DynamoDB)
     * 
     * @throws \RuntimeException Se $query não for um array válido
     * 
     * @example
     * // Executado internamente pelo Query Builder
     * $compiled = [
     *     'params' => [
     *         'TableName' => 'users',
     *         'Key' => ['id' => 'user123']
     *     ]
     * ];
     * $connection->delete($compiled);
     * 
     * @since 1.0.0
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
     * Remove um item do DynamoDB identificado por sua chave primária.
     * Realiza marshaling automático da chave.
     *
     * @param array $compiled Array compilado contendo params com TableName e Key
     * 
     * @return void
     * 
     * @since 1.0.0
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
     * Cria uma nova instância do Query Builder customizado para DynamoDB
     * que compila queries Eloquent em operações DynamoDB.
     *
     * @return \Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder Query builder DynamoDB
     * 
     * @example
     * $query = $connection->query();
     * $users = $query->from('users')->where('status', 'active')->get();
     * 
     * @since 1.0.0
     */
    public function query()
    {
        return new \Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder($this);
    }

    /**
     * Get the table name.
     * 
     * Retorna o nome da tabela DynamoDB configurada para esta conexão.
     *
     * @return string Nome da tabela DynamoDB
     * 
     * @example
     * $tableName = $connection->getTableName(); // 'users'
     * 
     * @since 1.0.0
     */
    public function getTableName()
    {
        return $this->getConfig('table');
    }
}
