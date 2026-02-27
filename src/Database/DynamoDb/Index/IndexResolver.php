<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Index;

use Illuminate\Database\Query\Builder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model;

/**
 * Resolve qual índice usar para uma query baseado nas condições where.
 */
class IndexResolver
{
    /**
     * Model instance para obter configuração de índices.
     *
     * @var Model|null
     */
    protected ?Model $model = null;

    /**
     * Partition Key da tabela.
     *
     * @var string|null
     */
    protected ?string $partitionKey = null;

    /**
     * Sort Key da tabela.
     *
     * @var string|null
     */
    protected ?string $sortKey = null;

    /**
     * GSI indexes configurados.
     *
     * @var array
     */
    protected array $gsiIndexes = [];

    /**
     * LSI indexes configurados.
     *
     * @var array
     */
    protected array $lsiIndexes = [];

    /**
     * Create a new IndexResolver instance.
     *
     * @param Model|null $model
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
     * @param Model $model
     * @return self
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
     * @param Builder $query
     * @return array|null ['index_name' => string, 'index_type' => 'gsi'|'lsi'|'primary', 'key_conditions' => array, 'filter_conditions' => array]
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
     * @param array $wheres
     * @return bool
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
     * @param array $wheres
     * @return array|null
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
     * Prioriza índices que tenham Partition Key presente nas condições WHERE.
     *
     * @param array $wheres
     * @return array|null
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
     * @param array $wheres
     * @param array $indexConfig
     * @param string $indexName
     * @param string $indexType
     * @return array|null
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
            // Condições Null/NotNull vão para FilterExpression (não são KeyCondition)
            if ($where['type'] === 'Null' || $where['type'] === 'NotNull') {
                $filterConditions[] = $where;
                continue;
            }

            // whereIn (In) vai para FilterExpression
            if ($where['type'] === 'In') {
                $filterConditions[] = $where;
                continue;
            }

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
     * @param array $wheres
     * @param array $keyColumns
     * @return array
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
     * @param string $column
     * @return bool
     */
    public function isPartitionKey(string $column): bool
    {
        return $column === $this->partitionKey;
    }

    /**
     * Check if a column is the sort key.
     *
     * @param string $column
     * @return bool
     */
    public function isSortKey(string $column): bool
    {
        return $column === $this->sortKey;
    }

    /**
     * Get partition key.
     *
     * @return string|null
     */
    public function getPartitionKey(): ?string
    {
        return $this->partitionKey;
    }

    /**
     * Get sort key.
     *
     * @return string|null
     */
    public function getSortKey(): ?string
    {
        return $this->sortKey;
    }
}
