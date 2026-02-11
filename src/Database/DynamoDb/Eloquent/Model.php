<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent;

use Illuminate\Database\Eloquent\Model as BaseModel;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Query\Builder as DynamoDbBuilder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Connection\DynamoDbConnection;

/**
 * DynamoDB Eloquent Model Base Class.
 * 
 * Classe base para models Eloquent que usam DynamoDB como backend.
 * Fornece funcionalidades específicas do DynamoDB como auto-criação de tabelas,
 * suporte a índices (GSI/LSI), e gerenciamento de chaves compostas.
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent
 * @since 1.0.0
 */
class Model extends BaseModel
{
    /**
     * Se deve criar tabela automaticamente quando não existir.
     * 
     * Quando true, a tabela DynamoDB será criada automaticamente
     * no primeiro insert se não existir.
     *
     * @var bool
     */
    protected $autoCreateTable = true;

    /**
     * Primary Key type - DynamoDB suporta string, number ou binary.
     * Por padrão, usamos string para maior flexibilidade.
     * 
     * Suporta UUIDs, IDs customizados, e outros formatos de string.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * DynamoDB não tem auto-increment, sempre false.
     * 
     * DynamoDB não gera IDs automaticamente. Use UUIDs ou
     * gere IDs manualmente.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Partition Key (Primary Key simples ou parte do Composite Key).
     * 
     * A partition key determina qual partição física do DynamoDB
     * armazena o item. Queries devem sempre incluir a partition key.
     *
     * @var string|null
     * 
     * @example
     * protected $partitionKey = 'user_id';
     */
    protected $partitionKey = null;

    /**
     * Sort Key (parte do Composite Key, opcional).
     * 
     * Quando usada com partition key, forma uma chave composta.
     * Permite queries com range (between, begins_with, <, >, etc).
     *
     * @var string|null
     * 
     * @example
     * protected $sortKey = 'created_at';
     */
    protected $sortKey = null;

    /**
     * Global Secondary Indexes (GSI).
     * 
     * GSIs permitem queries por atributos que não são a chave primária.
     * Cada GSI tem sua própria partition key e sort key opcional.
     *
     * @var array
     * 
     * @example
     * protected $gsiIndexes = [
     *     'email-index' => [
     *         'partition_key' => 'email',
     *         'projection_type' => 'ALL'
     *     ],
     *     'status-created-index' => [
     *         'partition_key' => 'status',
     *         'sort_key' => 'created_at',
     *         'projection_type' => 'ALL'
     *     ]
     * ];
     */
    protected $gsiIndexes = [];

    /**
     * Local Secondary Indexes (LSI).
     * 
     * LSIs compartilham a partition key da tabela mas usam
     * um sort key alternativo. Limitado a 5 LSIs por tabela.
     *
     * @var array
     * 
     * @example
     * protected $lsiIndexes = [
     *     'user-email-index' => [
     *         'sort_key' => 'email',
     *         'projection_type' => 'ALL'
     *     ]
     * ];
     */
    protected $lsiIndexes = [];

    /**
     * Field normalizers configuration.
     * 
     * Configurações para normalização de campos antes de salvar.
     *
     * @var array
     */
    protected $fieldNormalizers = [];

    /**
     * Boot the model.
     * 
     * Remove atributos null/vazios antes de salvar pois DynamoDB não aceita
     * valores null. Valores vazios são automaticamente removidos do item.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $attributes = $model->getAttributes();
            foreach ($attributes as $key => $value) {
                if (is_null($value) || $value === '') {
                    unset($model->$key);
                }
            }
        });
    }

    /**
     * Get the connection name for the model.
     * 
     * Determina dinamicamente a conexão DynamoDB a usar baseado em:
     * 1. Propriedade $connection do model (se definida)
     * 2. config('dynamodb.on_connection') ou config('database-dynamodb.on_connection')
     * 3. config('dynamodb.default') como fallback
     * 
     * Permite usar diferentes conexões (aws, local) dinamicamente.
     *
     * @return string|null Nome da conexão DynamoDB configurada
     * 
     * @example
     * // No model
     * protected $connection = 'aws'; // Força uso da conexão AWS
     * 
     * // No config
     * 'on_connection' => env('DYNAMODB_CONNECTION', 'local') // Controla via ENV
     * 
     * @since 1.0.0
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
     * Cria uma instância do Query Builder customizado para DynamoDB
     * que suporta resolução automática de índices e compilação de queries
     * para operações DynamoDB.
     *
     * @return DynamoDbBuilder Query builder DynamoDB configurado
     * 
     * @since 1.0.0
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
     * Create a new Eloquent query builder for the model.
     * 
     * Cria um Eloquent Builder customizado para DynamoDB que herda
     * funcionalidades do Laravel mas adiciona suporte específico ao DynamoDB.
     *
     * @param \Illuminate\Database\Query\Builder $query Query builder base
     * 
     * @return \Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Builder Eloquent builder DynamoDB
     * 
     * @since 1.0.0
     */
    public function newEloquentBuilder($query)
    {
        return new \Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Builder($query);
    }

    /**
     * Perform a model insert operation.
     * 
     * Executa insert (PutItem) no DynamoDB. Garante que a tabela existe,
     * gera UUID para partition key se necessário, e adiciona timestamps.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent builder
     * 
     * @return bool True se insert foi bem sucedido
     * 
     * @throws \RuntimeException Se houver erro ao criar tabela ou inserir item
     * 
     * @example
     * $user = new User(['name' => 'John', 'email' => 'john@example.com']);
     * $user->save(); // Chama performInsert internamente
     * 
     * @since 1.0.0
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
     * Executa update (UpdateItem) no DynamoDB. Atualiza apenas os atributos
     * modificados (dirty). Não permite modificar partition key ou sort key.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent builder
     * 
     * @return bool True se houve atributos para atualizar e update foi bem sucedido
     * 
     * @example
     * $user = User::find('user123');
     * $user->name = 'John Updated';
     * $user->save(); // Chama performUpdate internamente
     * 
     * @since 1.0.0
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

        // Remover a chave primária do array de dirty para evitar tentar atualizá-la
        $partitionKey = $this->getPartitionKey();
        if (isset($dirty[$partitionKey])) {
            unset($dirty[$partitionKey]);
        }

        // Remover sort key se existir
        $sortKey = $this->getSortKey();
        if ($sortKey && isset($dirty[$sortKey])) {
            unset($dirty[$sortKey]);
        }

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
     * Executa delete (DeleteItem) no DynamoDB identificando o item
     * pela chave primária (partition key e sort key se existir).
     *
     * @return bool True se delete foi bem sucedido
     * 
     * @example
     * $user = User::find('user123');
     * $user->delete(); // Chama performDeleteOnModel internamente
     * 
     * @since 1.0.0
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
     * Set the keys for a save update query.
     * 
     * Configura a query com WHERE na chave primária (partition key e sort key).
     * Usa getOriginal() para preservar o ID original mesmo após fill().
     * Essencial para garantir que updates funcionem corretamente.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Eloquent builder
     * 
     * @return \Illuminate\Database\Eloquent\Builder Query com WHERE configurado
     * 
     * @throws \RuntimeException Se partition key não tiver valor
     * 
     * @since 1.0.0
     */
    protected function setKeysForSaveQuery($query)
    {
        $partitionKey = $this->getPartitionKey();
        
        // Prioridade: 1) getOriginal (preserva ID antes do fill), 2) getKey() atual, 3) getAttribute
        // getOriginal() é importante porque preserva o valor antes do fill()
        $keyValue = $this->getOriginal($partitionKey);
        
        if (empty($keyValue)) {
            $keyValue = $this->getKey();
        }
        
        if (empty($keyValue)) {
            $keyValue = $this->getAttribute($partitionKey);
        }
        
        if (empty($keyValue)) {
            throw new \RuntimeException("Cannot update model without primary key value. Partition key: {$partitionKey}");
        }
        
        $query->where($partitionKey, '=', $keyValue);
        
        // Se tiver sort key, adicionar também
        $sortKey = $this->getSortKey();
        if ($sortKey) {
            // Prioridade: original primeiro, depois atributo atual
            $sortKeyValue = $this->getOriginal($sortKey) ?? $this->getAttribute($sortKey);
            if (!empty($sortKeyValue)) {
                $query->where($sortKey, '=', $sortKeyValue);
            }
        }

        return $query;
    }

    /**
     * Get the partition key.
     * 
     * Retorna o nome da partition key do model. Se não definida,
     * usa o valor de getKeyName() (padrão 'id').
     *
     * @return string Nome da partition key
     * 
     * @example
     * protected $partitionKey = 'user_id';
     * $key = $this->getPartitionKey(); // 'user_id'
     * 
     * @since 1.0.0
     */
    public function getPartitionKey()
    {
        return $this->partitionKey ?? $this->getKeyName();
    }

    /**
     * Get the sort key.
     * 
     * Retorna o nome da sort key do model se definida.
     * Null indica que a tabela usa apenas partition key.
     *
     * @return string|null Nome da sort key ou null
     * 
     * @example
     * protected $sortKey = 'created_at';
     * $key = $this->getSortKey(); // 'created_at'
     * 
     * @since 1.0.0
     */
    public function getSortKey()
    {
        return $this->sortKey;
    }

    /**
     * Get GSI indexes.
     * 
     * Retorna a configuração de todos os Global Secondary Indexes do model.
     * GSIs permitem queries por atributos alternativos.
     *
     * @return array Array associativo de configurações GSI
     * 
     * @example
     * $indexes = $this->getGsiIndexes();
     * // ['email-index' => ['partition_key' => 'email', ...]]
     * 
     * @since 1.0.0
     */
    public function getGsiIndexes()
    {
        return $this->gsiIndexes;
    }

    /**
     * Get LSI indexes.
     * 
     * Retorna a configuração de todos os Local Secondary Indexes do model.
     * LSIs compartilham a partition key mas usam sort key alternativo.
     *
     * @return array Array associativo de configurações LSI
     * 
     * @since 1.0.0
     */
    public function getLsiIndexes()
    {
        return $this->lsiIndexes;
    }

    /**
     * Get field normalizers configuration.
     * 
     * Retorna configurações de normalização de campos.
     *
     * @return array Array de configurações de normalização
     * 
     * @since 1.0.0
     */
    public function getFieldNormalizers()
    {
        return $this->fieldNormalizers;
    }

    /**
     * Garantir que a tabela existe. Cria automaticamente se não existir.
     * 
     * Se $autoCreateTable for true, verifica se a tabela existe no DynamoDB
     * e cria automaticamente caso não exista. Usa configurações do model
     * (partition key, sort key, GSI, LSI) para criar a tabela.
     *
     * @return bool True se tabela existe ou foi criada, false se erro
     * 
     * @example
     * $user = new User();
     * $user->ensureTableExists(); // Cria tabela 'users' se não existir
     * 
     * @since 1.0.0
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
     * Cria a tabela DynamoDB usando configurações do model (partition key,
     * sort key, GSI, LSI). Usa BillingMode PAY_PER_REQUEST (on-demand).
     * Aguarda a tabela ficar ativa antes de retornar.
     *
     * @return bool True se tabela foi criada ou já existia
     * 
     * @throws \RuntimeException Se conexão não for DynamoDbConnection
     * @throws \Exception Se erro ao criar tabela (exceto se já existe)
     * 
     * @example
     * $user = new User();
     * $user->createTable(); // Cria tabela 'users' com configurações do model
     * 
     * @since 1.0.0
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
     * Gera AttributeDefinitions para CreateTable incluindo partition key,
     * sort key, e keys de todos os índices (GSI e LSI). Apenas atributos
     * usados em keys precisam ser definidos.
     *
     * @return array Array de AttributeDefinitions para DynamoDB
     * 
     * @since 1.0.0
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
     * Gera KeySchema (partition key e sort key se existir) para CreateTable.
     *
     * @return array Array com KeySchema (HASH e RANGE)
     * 
     * @since 1.0.0
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
     * Converte configuração de GSI do model para formato CreateTable do DynamoDB.
     *
     * @param array $gsiIndexes Configurações GSI do model
     * 
     * @return array Array de definições GSI para DynamoDB
     * 
     * @since 1.0.0
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
     * Converte configuração de LSI do model para formato CreateTable do DynamoDB.
     * LSI sempre usa a partition key da tabela.
     *
     * @param array $lsiIndexes Configurações LSI do model
     * 
     * @return array Array de definições LSI para DynamoDB
     * 
     * @since 1.0.0
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
     * Determina o tipo de atributo DynamoDB (S=String, N=Number, B=Binary)
     * baseado nos casts definidos no model. Por padrão, usa String.
     * Timestamps são convertidos para Number (Unix timestamp).
     *
     * @param string $attributeName Nome do atributo
     * 
     * @return string Tipo DynamoDB: 'S' (String), 'N' (Number), ou 'B' (Binary)
     * 
     * @example
     * // Model com cast
     * protected $casts = ['age' => 'int', 'created_at' => 'datetime'];
     * 
     * $this->getAttributeType('age'); // 'N'
     * $this->getAttributeType('created_at'); // 'N' (timestamp)
     * $this->getAttributeType('name'); // 'S' (padrão)
     * 
     * @since 1.0.0
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
