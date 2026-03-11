<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model as DynamoDbModel;
use Illuminate\Pagination\Paginator;

class Builder extends BaseBuilder
{
    /**
     * Model instance associated with this query (for index resolution).
     *
     * @var DynamoDbModel|null
     */
    protected ?DynamoDbModel $model = null;

    /**
     * Set the model instance.
     *
     * @param DynamoDbModel $model
     * @return self
     */
    public function setModel(DynamoDbModel $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Get the model instance.
     *
     * @return DynamoDbModel|null
     */
    public function getModel(): ?DynamoDbModel
    {
        return $this->model;
    }

    /**
     * Paginate the given query using cursor-based pagination.
     * DynamoDB não suporta OFFSET, então usamos LastEvaluatedKey (cursor).
     *
     * @param int $perPage
     * @param array $columns
     * @param string $cursorName
     * @param string|null $cursor
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = 15, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        // Se não foi passado cursor explicitamente, tenta pegar da query string
        if ($cursor === null && function_exists('request')) {
            $cursor = request()->get($cursorName);
        }

        // Decodificar cursor (base64 do LastEvaluatedKey)
        $lastEvaluatedKey = null;
        if ($cursor && is_string($cursor)) {
            $decoded = json_decode(base64_decode($cursor), true);
            if (is_array($decoded)) {
                $lastEvaluatedKey = $decoded;
            }
        }

        // Compilar a query
        $compiled = $this->grammar->compileSelect($this);
        
        if (!isset($compiled['operation']) || !isset($compiled['params'])) {
            // Fallback para array vazio se compilação falhar
            return new Paginator([], $perPage, null, [
                'path' => Paginator::resolveCurrentPath(),
                'cursorName' => $cursorName,
            ]);
        }

        $operation = $compiled['operation'];
        $params = $compiled['params'];

        // Adicionar ExclusiveStartKey se houver cursor (apenas atributos da chave do índice/tabela)
        if ($lastEvaluatedKey) {
            $lastEvaluatedKey = $this->filterToKeyAttributesForCursor($lastEvaluatedKey, $params);
            if (! empty($lastEvaluatedKey)) {
                $params['ExclusiveStartKey'] = $this->connection->getMarshaler()->marshalItem($lastEvaluatedKey);
            }
        }

        $hasFilterExpression = ! empty($params['FilterExpression']);

        // Com FilterExpression, o Limit do DynamoDB aplica aos itens LIDOS antes do filtro.
        // Uma única chamada com Limit = perPage+1 pode devolver 0 itens. Por isso fazemos loop
        // até reunir perPage+1 itens que passem no filtro (ou acabar a busca).
        if (! $hasFilterExpression) {
            $params['Limit'] = $perPage + 1;
        }

        // Executar a operação no DynamoDB
        try {
            $client = $this->connection->getDynamoDbClient();
            $marshaler = $this->connection->getMarshaler();

            if (isset($params['ExpressionAttributeValues']) && ! empty($params['ExpressionAttributeValues'])) {
                $params['ExpressionAttributeValues'] = $marshaler->marshalItem($params['ExpressionAttributeValues']);
            }

            $items = [];
            $nextCursor = null;
            $hasMorePages = false;

            if ($hasFilterExpression) {
                // Tamanho de cada página lida do DynamoDB (evita ler a tabela inteira de uma vez)
                $readPageSize = min(100, max($perPage + 1, 50));
                $params['Limit'] = $readPageSize;
                $collected = [];
                $currentParams = $params;
                $lastEvaluatedKey = null;

                do {
                    if ($operation === 'Query') {
                        $result = $client->query($currentParams);
                    } else {
                        $result = $client->scan($currentParams);
                    }

                    $lastEvaluatedKey = $result['LastEvaluatedKey'] ?? null;
                    $rawItems = $result['Items'] ?? [];
                    foreach ($rawItems as $item) {
                        $collected[] = (object) $marshaler->unmarshalItem($item);
                        if (count($collected) >= $perPage + 1) {
                            break 2;
                        }
                    }

                    if (empty($lastEvaluatedKey)) {
                        break;
                    }

                    $currentParams = array_merge($params, [
                        'ExclusiveStartKey' => $lastEvaluatedKey,
                    ]);
                } while (true);

                $items = array_slice($collected, 0, $perPage + 1);
                $hasMorePages = count($collected) > $perPage || ! empty($lastEvaluatedKey);
                if (count($items) > $perPage) {
                    array_pop($items);
                }
                // Com FilterExpression, o cursor DEVE ser a chave do último item devolvido na página,
                // não o LastEvaluatedKey do DynamoDB. Caso contrário itens válidos são pulados:
                // ex. lemos 100, retornamos 11, cursor = key do 100º → próxima página começa após o 100º.
                if ($hasMorePages && count($items) > 0) {
                    $lastShownItem = $items[count($items) - 1];
                    $keyOnly = $this->filterToKeyAttributesForCursor((array) $lastShownItem, $params);
                    $nextCursor = ! empty($keyOnly) ? base64_encode(json_encode($keyOnly)) : null;
                }
            } else {
                if ($operation === 'Query') {
                    $result = $client->query($params);
                } else {
                    $result = $client->scan($params);
                }

                $items = array_map(
                    fn ($item) => (object) $marshaler->unmarshalItem($item),
                    $result['Items'] ?? []
                );

                $hasMorePages = count($items) > $perPage;
                if ($hasMorePages && ! empty($result['LastEvaluatedKey'])) {
                    $nextCursor = base64_encode(json_encode($marshaler->unmarshalItem($result['LastEvaluatedKey'])));
                } elseif ($hasMorePages && isset($items[$perPage - 1])) {
                    // Primeira página: às vezes o DynamoDB não devolve LastEvaluatedKey.
                    // ExclusiveStartKey = "começar depois deste item" → usar o último item que mostramos.
                    $keyOnly = $this->filterToKeyAttributesForCursor((array) $items[$perPage - 1], $params);
                    $nextCursor = ! empty($keyOnly) ? base64_encode(json_encode($keyOnly)) : null;
                }
                if ($hasMorePages) {
                    array_pop($items);
                }
            }

            // Criar paginator
            $paginator = new Paginator(
                $items,
                $perPage,
                null, // current page não é usado em cursor pagination
                [
                    'path' => Paginator::resolveCurrentPath(),
                    'cursorName' => $cursorName,
                ]
            );

            // Armazenar next_cursor como propriedade custom do paginator
            $paginator->hasMorePagesWhen($hasMorePages);
            
            // Adicionar next_cursor nos metadados do paginator
            if ($nextCursor) {
                $paginator->appends([$cursorName => $nextCursor]);
            }

            return $paginator;

        } catch (\Exception $e) {
            // Em caso de erro, retornar paginator vazio
            if (app()->bound('log')) {
                app('log')->error('DynamoDB simplePaginate error', [
                    'table' => $params['TableName'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }

            return new Paginator([], $perPage, null, [
                'path' => Paginator::resolveCurrentPath(),
                'cursorName' => $cursorName,
            ]);
        }
    }

    /**
     * Retorna os nomes dos atributos que compõem a chave para ExclusiveStartKey
     * (índice ou tabela), para que o cursor não envie atributos extras ou null.
     *
     * @param array $params Params compilados (TableName, IndexName, etc.)
     * @return array<string>|null
     */
    protected function getKeyAttributeNamesForCursor(array $params): ?array
    {
        $model = $this->getModel();
        if (! $model) {
            return null;
        }

        $indexName = $params['IndexName'] ?? null;
        $tablePartitionKey = $model->getPartitionKey();
        $tableSortKey = $model->getSortKey();

        if ($indexName && $tablePartitionKey) {
            $gsiIndexes = $model->getGsiIndexes();
            $indexConfig = $gsiIndexes[$indexName] ?? null;
            if ($indexConfig && isset($indexConfig['partition_key'])) {
                $keys = [$indexConfig['partition_key']];
                if (! empty($indexConfig['sort_key'])) {
                    $keys[] = $indexConfig['sort_key'];
                }
                $keys[] = $tablePartitionKey;
                return array_values(array_unique($keys));
            }
        }

        $keys = array_filter([$tablePartitionKey, $tableSortKey]);
        return empty($keys) ? null : array_values($keys);
    }

    /**
     * Filtra um item/array para conter apenas os atributos da chave do índice/tabela
     * e remove valores null (DynamoDB não aceita null em chave).
     *
     * @param array $item Item completo ou decoded cursor
     * @param array $params Params compilados da query
     * @return array
     */
    protected function filterToKeyAttributesForCursor(array $item, array $params): array
    {
        $keyNames = $this->getKeyAttributeNamesForCursor($params);
        if (empty($keyNames)) {
            return $item;
        }

        $filtered = [];
        foreach ($keyNames as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '') {
                $filtered[$key] = $item[$key];
            }
        }

        return $filtered;
    }
}

