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

                $result = $this->dynamoDbClient->query($params);
                $items = array_map(
                    fn($item) => $this->marshaler->unmarshalItem($item),
                    $result['Items'] ?? []
                );
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
                break;

            default:
                throw new \RuntimeException("Unknown operation: {$operation}");
        }

        // Converter para objetos (compatível com Laravel)
        return array_map(fn($item) => (object) $item, $items);
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

        $this->dynamoDbClient->putItem([
            'TableName' => $params['TableName'] ?? $this->getConfig('table'),
            'Item' => $this->marshaler->marshalItem($params['Item'] ?? $params),
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

