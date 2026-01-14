<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder as DynamoDbBuilder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;

class Model extends BaseModel
{
    /**
     * Se deve criar tabela automaticamente quando não existir.
     *
     * @var bool
     */
    protected $autoCreateTable = true;

    /**
     * Primary Key type - DynamoDB suporta string, number ou binary.
     * Por padrão, usamos string para maior flexibilidade.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * DynamoDB não tem auto-increment, sempre false.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Partition Key (Primary Key simples ou parte do Composite Key).
     *
     * @var string|null
     */
    protected $partitionKey = null;

    /**
     * Sort Key (parte do Composite Key, opcional).
     *
     * @var string|null
     */
    protected $sortKey = null;

    /**
     * Global Secondary Indexes (GSI).
     *
     * @var array
     */
    protected $gsiIndexes = [];

    /**
     * Local Secondary Indexes (LSI).
     *
     * @var array
     */
    protected $lsiIndexes = [];

    /**
     * Get the connection name for the model.
     * Valida dinamicamente usando config('dynamodb.on_connection') ou config('database-dynamodb.on_connection').
     *
     * @return string|null
     */
    public function getConnectionName()
    {
        // Se o modelo já tem uma conexão definida explicitamente, usar ela
        if (isset($this->connection)) {
            return $this->connection;
        }

        // Tentar usar on_connection do config (prioridade)
        $onConnection = config('dynamodb.on_connection') ?? config('database-dynamodb.on_connection');
        if ($onConnection) {
            return $onConnection;
        }

        // Fallback para default do config
        return config('dynamodb.default') ?? config('database-dynamodb.default', 'local');
    }

    /**
     * Get a new query builder instance for the connection.
     *
     * @return DynamoDbBuilder
     */
    public function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        $builder = new DynamoDbBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        );

        // Associar o model ao builder para permitir resolução de índices
        $builder->setModel($this);

        return $builder;
    }

    /**
     * Perform a model insert operation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return bool
     */
    protected function performInsert(\Illuminate\Database\Eloquent\Builder $query)
    {
        // Garantir que a tabela existe antes de inserir
        $this->ensureTableExists();

        // Adicionar timestamps se necessário
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Obter atributos
        $attributes = $this->getAttributes();

        // Garantir que a partition key está presente
        $partitionKey = $this->getPartitionKey();
        if (empty($attributes[$partitionKey])) {
            // Tentar obter do atributo diretamente (pode ter sido definido no evento creating)
            $id = $this->getAttribute($partitionKey);
            if (empty($id)) {
                // Se ainda não tiver, gerar UUID
                $id = \Illuminate\Support\Str::uuid()->toString();
                $this->setAttribute($partitionKey, $id);
            }
            $attributes[$partitionKey] = $id;
        }

        // Executar insert via connection
        $query->getConnection()->insert(
            $query->getQuery()->getGrammar()->compileInsert($query->getQuery(), $attributes)
        );

        // Marcar como sincronizado
        $this->syncOriginal();

        return true;
    }

    /**
     * Perform a model update operation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return bool
     */
    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query)
    {
        // Garantir que a tabela existe antes de atualizar
        $this->ensureTableExists();

        // Adicionar updated_at se necessário
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Obter atributos modificados
        $dirty = $this->getDirty();

        if (count($dirty) === 0) {
            return false;
        }

        // Construir query com where na primary key
        $this->setKeysForSaveQuery($query);

        // Executar update via connection
        $query->getConnection()->update(
            $query->getQuery()->getGrammar()->compileUpdate($query->getQuery(), $dirty)
        );

        // Marcar como sincronizado
        $this->syncOriginal();

        return true;
    }

    /**
     * Perform a model delete operation.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return bool
     */
    protected function performDeleteOnModel()
    {
        $query = $this->newModelQuery();

        // Construir query com where na primary key
        $this->setKeysForSaveQuery($query);

        // Executar delete via connection
        $query->getConnection()->delete(
            $query->getQuery()->getGrammar()->compileDelete($query->getQuery())
        );

        return true;
    }

    /**
     * Get the partition key.
     *
     * @return string|null
     */
    public function getPartitionKey()
    {
        return $this->partitionKey ?? $this->getKeyName();
    }

    /**
     * Get the sort key.
     *
     * @return string|null
     */
    public function getSortKey()
    {
        return $this->sortKey;
    }

    /**
     * Get GSI indexes.
     *
     * @return array
     */
    public function getGsiIndexes()
    {
        return $this->gsiIndexes;
    }

    /**
     * Get LSI indexes.
     *
     * @return array
     */
    public function getLsiIndexes()
    {
        return $this->lsiIndexes;
    }

    /**
     * Garantir que a tabela existe. Cria automaticamente se não existir.
     *
     * @return bool
     */
    public function ensureTableExists(): bool
    {
        if (!$this->autoCreateTable) {
            return true;
        }

        $connection = $this->getConnection();

        if (!$connection instanceof DynamoDbConnection) {
            return false;
        }

        $client = $connection->getDynamoDbClient();
        $tableName = $this->getTable();

        // Verificar se tabela existe
        try {
            $client->describeTable(['TableName' => $tableName]);
            return true; // Tabela já existe
        } catch (\Exception $e) {
            // Tabela não existe, criar automaticamente
            try {
                return $this->createTable();
            } catch (\Exception $createException) {
                // Log erro mas não falhar (em produção, pode querer tratar diferente)
                if (app()->bound('log')) {
                    app('log')->warning("Failed to auto-create DynamoDB table {$tableName}: " . $createException->getMessage());
                }
                return false;
            }
        }
    }

    /**
     * Criar a tabela no DynamoDB automaticamente.
     *
     * @return bool
     */
    public function createTable(): bool
    {
        $connection = $this->getConnection();

        if (!$connection instanceof DynamoDbConnection) {
            throw new \RuntimeException('DynamoDB Model requires DynamoDbConnection');
        }

        $client = $connection->getDynamoDbClient();
        $tableName = $this->getTable();

        // Construir definição da tabela
        $tableDefinition = [
            'TableName' => $tableName,
            'AttributeDefinitions' => $this->getAttributeDefinitions(),
            'KeySchema' => $this->getKeySchema(),
            'BillingMode' => 'PAY_PER_REQUEST', // On-demand billing
        ];

        // Adicionar GSI indexes
        $gsiIndexes = $this->getGsiIndexes();
        if (!empty($gsiIndexes)) {
            $tableDefinition['GlobalSecondaryIndexes'] = $this->buildGsiDefinitions($gsiIndexes);
        }

        // Adicionar LSI indexes (se tiver Sort Key)
        $lsiIndexes = $this->getLsiIndexes();
        if (!empty($lsiIndexes) && $this->getSortKey()) {
            $tableDefinition['LocalSecondaryIndexes'] = $this->buildLsiDefinitions($lsiIndexes);
        }

        try {
            $client->createTable($tableDefinition);

            // Aguardar tabela ficar ativa
            $client->waitUntil('TableExists', [
                'TableName' => $tableName,
                '@waiter' => [
                    'delay' => 1,
                    'maxAttempts' => 30,
                ],
            ]);

            return true;
        } catch (\Exception $e) {
            // Ignorar erro se tabela já existe
            if (str_contains($e->getMessage(), 'already exists') ||
                str_contains($e->getMessage(), 'ResourceInUseException')) {
                return true;
            }
            throw $e;
        }
    }

    /**
     * Obter definições de atributos para a tabela.
     *
     * @return array
     */
    protected function getAttributeDefinitions(): array
    {
        $attributes = [];
        $processed = [];

        // Partition Key
        $partitionKey = $this->getPartitionKey();
        if ($partitionKey && !in_array($partitionKey, $processed)) {
            $attributes[] = [
                'AttributeName' => $partitionKey,
                'AttributeType' => $this->getAttributeType($partitionKey),
            ];
            $processed[] = $partitionKey;
        }

        // Sort Key
        $sortKey = $this->getSortKey();
        if ($sortKey && !in_array($sortKey, $processed)) {
            $attributes[] = [
                'AttributeName' => $sortKey,
                'AttributeType' => $this->getAttributeType($sortKey),
            ];
            $processed[] = $sortKey;
        }

        // GSI Keys
        foreach ($this->getGsiIndexes() as $indexConfig) {
            if (!empty($indexConfig['partition_key']) && !in_array($indexConfig['partition_key'], $processed)) {
                $attributes[] = [
                    'AttributeName' => $indexConfig['partition_key'],
                    'AttributeType' => $this->getAttributeType($indexConfig['partition_key']),
                ];
                $processed[] = $indexConfig['partition_key'];
            }

            if (!empty($indexConfig['sort_key']) && !in_array($indexConfig['sort_key'], $processed)) {
                $attributes[] = [
                    'AttributeName' => $indexConfig['sort_key'],
                    'AttributeType' => $this->getAttributeType($indexConfig['sort_key']),
                ];
                $processed[] = $indexConfig['sort_key'];
            }
        }

        // LSI Keys (se existir)
        foreach ($this->getLsiIndexes() as $indexConfig) {
            if (!empty($indexConfig['sort_key']) && !in_array($indexConfig['sort_key'], $processed)) {
                $attributes[] = [
                    'AttributeName' => $indexConfig['sort_key'],
                    'AttributeType' => $this->getAttributeType($indexConfig['sort_key']),
                ];
                $processed[] = $indexConfig['sort_key'];
            }
        }

        return $attributes;
    }

    /**
     * Obter Key Schema para a tabela.
     *
     * @return array
     */
    protected function getKeySchema(): array
    {
        $schema = [
            ['AttributeName' => $this->getPartitionKey(), 'KeyType' => 'HASH'],
        ];

        if ($this->getSortKey()) {
            $schema[] = [
                'AttributeName' => $this->getSortKey(),
                'KeyType' => 'RANGE',
            ];
        }

        return $schema;
    }

    /**
     * Construir definições de GSI.
     *
     * @param array $gsiIndexes
     * @return array
     */
    protected function buildGsiDefinitions(array $gsiIndexes): array
    {
        $definitions = [];

        foreach ($gsiIndexes as $indexName => $indexConfig) {
            $gsi = [
                'IndexName' => $indexName,
                'KeySchema' => [
                    ['AttributeName' => $indexConfig['partition_key'], 'KeyType' => 'HASH'],
                ],
                'Projection' => [
                    'ProjectionType' => $indexConfig['projection_type'] ?? 'ALL',
                ],
            ];

            // Adicionar Sort Key se existir
            if (!empty($indexConfig['sort_key'])) {
                $gsi['KeySchema'][] = [
                    'AttributeName' => $indexConfig['sort_key'],
                    'KeyType' => 'RANGE',
                ];
            }

            $definitions[] = $gsi;
        }

        return $definitions;
    }

    /**
     * Construir definições de LSI.
     *
     * @param array $lsiIndexes
     * @return array
     */
    protected function buildLsiDefinitions(array $lsiIndexes): array
    {
        $definitions = [];

        foreach ($lsiIndexes as $indexName => $indexConfig) {
            $lsi = [
                'IndexName' => $indexName,
                'KeySchema' => [
                    ['AttributeName' => $this->getPartitionKey(), 'KeyType' => 'HASH'],
                    ['AttributeName' => $indexConfig['sort_key'], 'KeyType' => 'RANGE'],
                ],
                'Projection' => [
                    'ProjectionType' => $indexConfig['projection_type'] ?? 'ALL',
                ],
            ];

            $definitions[] = $lsi;
        }

        return $definitions;
    }

    /**
     * Detectar tipo de atributo DynamoDB a partir dos casts do model.
     *
     * @param string $attributeName
     * @return string S (String), N (Number), B (Binary)
     */
    protected function getAttributeType(string $attributeName): string
    {
        // Primary Key sempre String por padrão no DynamoDB (pode ser sobrescrito se necessário)
        $primaryKey = $this->getKeyName();
        if ($attributeName === $primaryKey) {
            // Se keyType for 'int', usar Number, caso contrário String
            if ($this->keyType === 'int') {
                return 'N';
            }
            // Por padrão, Primary Key é String (mais flexível - aceita UUIDs, IDs customizados, etc.)
            return 'S';
        }

        // Verificar se tem cast definido
        $casts = method_exists($this, 'getCasts') ? $this->getCasts() : [];

        // Se não tiver getCasts(), tentar método casts() (Laravel 10+)
        if (empty($casts) && method_exists($this, 'casts')) {
            $casts = $this->casts();
        }

        if (isset($casts[$attributeName])) {
            $cast = $casts[$attributeName];

            // Extrair apenas o tipo base (remover opções como 'decimal:2')
            $castType = is_string($cast) ? explode(':', $cast)[0] : $cast;

            // Tipos numéricos
            if (in_array($castType, ['int', 'integer', 'float', 'double', 'decimal', 'real'])) {
                return 'N';
            }

            // Tipos de data/timestamp (guardamos como Number - Unix timestamp)
            // IMPORTANTE: Quando inserir dados, converta DateTime para timestamp Unix
            // Exemplo: $item['created_at'] = Carbon::parse($date)->timestamp;
            if (in_array($castType, ['datetime', 'timestamp', 'date'])) {
                return 'N';
            }

            // Array/JSON (será serializado como String)
            if (in_array($castType, ['array', 'json', 'object', 'collection'])) {
                return 'S';
            }
        }

        // Por padrão, assume String
        return 'S';
    }
}
