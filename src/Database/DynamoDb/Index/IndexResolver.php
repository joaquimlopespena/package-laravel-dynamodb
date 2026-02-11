<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Index;

use Illuminate\Database\Query\Builder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model;

/**
 * DynamoDB Index Resolver Class.
 * 
 * Analisa condições WHERE de uma query e determina automaticamente
 * o melhor índice (Primary Key, GSI ou LSI) para executar a query.
 * 
 * O IndexResolver maximiza performance escolhendo índices que:
 * - Minimizam uso de RCU (Read Capacity Units)
 * - Evitam Scan sempre que possível
 * - Priorizam Query com índices sobre FilterExpression
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Index
 * @since 1.0.0
 */
class IndexResolver
{
    /**
     * Model instance para obter configuração de índices.
     * 
     * O model define partition key, sort key, GSI e LSI disponíveis.
     *
     * @var Model|null
     */
    protected ?Model $model = null;

    /**
     * Partition Key da tabela.
     * 
     * Chave de particionamento que determina a distribuição física dos dados.
     *
     * @var string|null
     */
    protected ?string $partitionKey = null;

    /**
     * Sort Key da tabela.
     * 
     * Chave de ordenação opcional que permite range queries.
     *
     * @var string|null
     */
    protected ?string $sortKey = null;

    /**
     * GSI indexes configurados.
     * 
     * Global Secondary Indexes com suas próprias partition e sort keys.
     *
     * @var array
     */
    protected array $gsiIndexes = [];

    /**
     * LSI indexes configurados.
     * 
     * Local Secondary Indexes que compartilham partition key mas têm sort key alternativo.
     *
     * @var array
     */
    protected array $lsiIndexes = [];

    /**
     * Create a new IndexResolver instance.
     * 
     * Inicializa o resolver opcionalmente com um model para
     * carregar configurações de índices imediatamente.
     *
     * @param Model|null $model Model DynamoDB com configurações de índices
     * 
     * @since 1.0.0
     */
    public function __construct(?Model $model = null)
    {
        if ($model) {
            $this->setModel($model);
        }
    }

    /**
     * Set the model and load its index configuration.
     * 
     * Carrega configurações de índices (partition key, sort key, GSI, LSI)
     * do model para uso na resolução de índices.
     *
     * @param Model $model Model DynamoDB
     * 
     * @return self Retorna a própria instância para method chaining
     * 
     * @since 1.0.0
     */
    public function setModel(Model $model): self
    {
        $this->model = $model;
        $this->partitionKey = $model->getPartitionKey();
        $this->sortKey = $model->getSortKey();
        $this->gsiIndexes = $model->getGsiIndexes();
        $this->lsiIndexes = $model->getLsiIndexes();

        return $this;
    }

    /**
     * Find the best index match for a query.
     * 
     * Analisa condições WHERE e retorna o melhor índice a usar.
     * Prioriza nesta ordem:
     * 1. Primary Key (GetItem/Query) - mais eficiente
     * 2. LSI (Local Secondary Index) - compartilha partition key
     * 3. GSI (Global Secondary Index) - permite queries alternativas
     * 4. null - indica que Scan é necessário (menos eficiente)
     *
     * @param Builder $query Query builder com condições WHERE
     * 
     * @return array|null Array com index_name, index_type, key_conditions, filter_conditions, has_partition_key, has_sort_key, sort_key ou null se nenhum índice aplicável
     * 
     * @example
     * // Query pode usar Primary Key
     * $match = $resolver->findBestIndex($query->where('id', 'user123'));
     * // ['index_type' => 'primary', 'key_conditions' => [...], ...]
     * 
     * // Query pode usar GSI
     * $match = $resolver->findBestIndex($query->where('email', 'john@example.com'));
     * // ['index_name' => 'email-index', 'index_type' => 'gsi', ...]
     * 
     * // Query precisa usar Scan
     * $match = $resolver->findBestIndex($query->where('random_field', 'value'));
     * // null (nenhum índice aplicável)
     * 
     * @since 1.0.0
     */
    public function findBestIndex(Builder $query): ?array
    {
        if (!$this->model) {
            return null;
        }

        $wheres = $query->wheres;

        // 1. Verificar se pode usar Primary Key (GetItem)
        if ($this->canUsePrimaryKey($wheres)) {
            return [
                'index_name' => null,
                'index_type' => 'primary',
                'key_conditions' => $this->extractKeyConditions($wheres, [$this->partitionKey, $this->sortKey]),
                'filter_conditions' => [],
                'sort_key' => $this->sortKey,
            ];
        }

        // 2. Verificar LSI (usa mesma Partition Key, diferente Sort Key)
        $lsiMatch = $this->findLsiMatch($wheres);
        if ($lsiMatch) {
            return $lsiMatch;
        }

        // 3. Verificar GSI (pode ter diferente Partition Key)
        // Priorizar GSI que tenha Partition Key nas condições
        $gsiMatch = $this->findGsiMatch($wheres);
        if ($gsiMatch) {
            return $gsiMatch;
        }

        // 4. Nenhum índice encontrado - deve usar Scan
        return null;
    }

    /**
     * Check if query can use Primary Key (GetItem or Query).
     * 
     * Verifica se as condições WHERE incluem a partition key com igualdade,
     * permitindo uso da Primary Key (operação mais eficiente).
     *
     * @param array $wheres Array de condições WHERE da query
     * 
     * @return bool True se pode usar Primary Key
     * 
     * @since 1.0.0
     */
    protected function canUsePrimaryKey(array $wheres): bool
    {
        // Precisa ter pelo menos Partition Key
        $hasPartitionKey = false;
        $hasSortKey = false;

        foreach ($wheres as $where) {
            if ($where['type'] === 'Basic' && $where['operator'] === '=') {
                if ($where['column'] === $this->partitionKey) {
                    $hasPartitionKey = true;
                }
                if ($this->sortKey && $where['column'] === $this->sortKey) {
                    $hasSortKey = true;
                }
            }
        }

        // Se não tem Sort Key, pode usar apenas Partition Key (Query)
        if (!$this->sortKey) {
            return $hasPartitionKey;
        }

        // Com Sort Key, precisa ter Partition Key (e opcionalmente Sort Key)
        return $hasPartitionKey;
    }

    /**
     * Find matching LSI index.
     * 
     * Busca um Local Secondary Index que possa ser usado.
     * LSI requer que a partition key esteja nas condições WHERE com igualdade.
     *
     * @param array $wheres Array de condições WHERE da query
     * 
     * @return array|null Informações do LSI encontrado ou null
     * 
     * @since 1.0.0
     */
    protected function findLsiMatch(array $wheres): ?array
    {
        if (empty($this->lsiIndexes) || !$this->partitionKey) {
            return null;
        }

        // LSI requer mesma Partition Key
        $hasPartitionKey = false;
        foreach ($wheres as $where) {
            if ($where['type'] === 'Basic' &&
                $where['operator'] === '=' &&
                $where['column'] === $this->partitionKey) {
                $hasPartitionKey = true;
                break;
            }
        }

        if (!$hasPartitionKey) {
            return null;
        }

        // Verificar cada LSI
        foreach ($this->lsiIndexes as $indexName => $indexConfig) {
            $match = $this->checkIndexMatch($wheres, $indexConfig, $indexName, 'lsi');
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Find matching GSI index.
     * 
     * Busca o melhor Global Secondary Index que possa ser usado.
     * Prioriza índices que tenham tanto partition key quanto sort key
     * nas condições WHERE.
     *
     * @param array $wheres Array de condições WHERE da query
     * 
     * @return array|null Informações do GSI encontrado ou null
     * 
     * @since 1.0.0
     */
    protected function findGsiMatch(array $wheres): ?array
    {
        if (empty($this->gsiIndexes)) {
            return null;
        }

        // Coletar todos os matches primeiro para poder priorizar
        $matches = [];

        foreach ($this->gsiIndexes as $indexName => $indexConfig) {
            $match = $this->checkIndexMatch($wheres, $indexConfig, $indexName, 'gsi');
            if ($match) {
                // Priorizar índices com base em quais keys estão nas condições:
                // Priority 3: Partition Key + Sort Key (ambos nas condições)
                // Priority 2: Partition Key (Sort Key não existe ou não está nas condições)
                // Priority 1: Apenas Partition Key (mas índice tem Sort Key que não está nas condições)
                // Priority 0: Sem Partition Key
                
                if ($match['has_partition_key'] && $match['has_sort_key']) {
                    $priority = 3; // Melhor match: PK + SK
                } elseif ($match['has_partition_key'] && empty($match['sort_key'])) {
                    $priority = 2; // PK sem SK no índice
                } elseif ($match['has_partition_key']) {
                    $priority = 1; // PK, mas SK do índice não está nas condições
                } else {
                    $priority = 0;
                }
                
                $matches[] = ['match' => $match, 'priority' => $priority];
            }
        }

        if (empty($matches)) {
            return null;
        }

        // Ordenar por prioridade (prioridade maior primeiro)
        usort($matches, fn($a, $b) => $b['priority'] <=> $a['priority']);

        // Retornar o melhor match
        return $matches[0]['match'];
    }

    /**
     * Check if where conditions match an index.
     * 
     * Verifica se condições WHERE são compatíveis com um índice específico.
     * Separa condições em key_conditions (usadas em KeyConditionExpression)
     * e filter_conditions (usadas em FilterExpression).
     *
     * @param array $wheres Array de condições WHERE
     * @param array $indexConfig Configuração do índice (partition_key, sort_key)
     * @param string $indexName Nome do índice
     * @param string $indexType Tipo do índice ('gsi' ou 'lsi')
     * 
     * @return array|null Informações do match ou null se não compatível
     * 
     * @since 1.0.0
     */
    protected function checkIndexMatch(array $wheres, array $indexConfig, string $indexName, string $indexType): ?array
    {
        $indexPartitionKey = $indexConfig['partition_key'] ?? null;
        $indexSortKey = $indexConfig['sort_key'] ?? null;

        if (!$indexPartitionKey) {
            return null;
        }

        $keyConditions = [];
        $filterConditions = [];
        $hasPartitionKey = false;
        $hasSortKey = false;

        foreach ($wheres as $where) {
            if ($where['type'] !== 'Basic') {
                continue;
            }

            $column = $where['column'];
            $operator = $where['operator'];
            $value = $where['value'];

            // Partition Key do índice precisa ser igualdade
            if ($column === $indexPartitionKey && $operator === '=') {
                $hasPartitionKey = true;
                $keyConditions[] = [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value,
                    'key_type' => 'partition',
                ];
            }
            // Sort Key do índice pode ser igualdade ou range
            elseif ($indexSortKey && $column === $indexSortKey &&
                    in_array($operator, ['=', '<', '<=', '>', '>=', 'between', 'begins_with'])) {
                $hasSortKey = true;
                $keyConditions[] = [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value,
                    'key_type' => 'sort',
                ];
            }
            // Outras condições vão para FilterExpression
            else {
                $filterConditions[] = $where;
            }
        }

        // Precisa ter pelo menos Partition Key
        if (!$hasPartitionKey) {
            return null;
        }

        return [
            'index_name' => $indexName,
            'index_type' => $indexType,
            'key_conditions' => $keyConditions,
            'filter_conditions' => $filterConditions,
            'has_partition_key' => $hasPartitionKey,
            'has_sort_key' => $hasSortKey,
            'sort_key' => $indexSortKey,
        ];
    }

    /**
     * Extract key conditions from where clauses.
     * 
     * Extrai condições WHERE que correspondem às key columns fornecidas.
     *
     * @param array $wheres Array de condições WHERE
     * @param array $keyColumns Array de nomes de colunas (partition key e sort key)
     * 
     * @return array Array de key conditions
     * 
     * @since 1.0.0
     */
    protected function extractKeyConditions(array $wheres, array $keyColumns): array
    {
        $keyConditions = [];

        foreach ($wheres as $where) {
            if ($where['type'] === 'Basic' && in_array($where['column'], $keyColumns)) {
                $keyConditions[] = [
                    'column' => $where['column'],
                    'operator' => $where['operator'],
                    'value' => $where['value'],
                ];
            }
        }

        return $keyConditions;
    }

    /**
     * Check if a column is the partition key.
     * 
     * Verifica se a coluna especificada é a partition key da tabela.
     *
     * @param string $column Nome da coluna
     * 
     * @return bool True se for a partition key
     * 
     * @since 1.0.0
     */
    public function isPartitionKey(string $column): bool
    {
        return $column === $this->partitionKey;
    }

    /**
     * Check if a column is the sort key.
     * 
     * Verifica se a coluna especificada é a sort key da tabela.
     *
     * @param string $column Nome da coluna
     * 
     * @return bool True se for a sort key
     * 
     * @since 1.0.0
     */
    public function isSortKey(string $column): bool
    {
        return $column === $this->sortKey;
    }

    /**
     * Get partition key.
     * 
     * Retorna o nome da partition key da tabela.
     *
     * @return string|null Nome da partition key
     * 
     * @since 1.0.0
     */
    public function getPartitionKey(): ?string
    {
        return $this->partitionKey;
    }

    /**
     * Get sort key.
     * 
     * Retorna o nome da sort key da tabela.
     *
     * @return string|null Nome da sort key
     * 
     * @since 1.0.0
     */
    public function getSortKey(): ?string
    {
        return $this->sortKey;
    }
}

