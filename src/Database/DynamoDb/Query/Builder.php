<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model as DynamoDbModel;
use Illuminate\Pagination\Paginator;

/**
 * DynamoDB Query Builder Class.
 * 
 * Extensão do Query Builder do Laravel com funcionalidades específicas
 * para DynamoDB, incluindo paginação por cursor (LastEvaluatedKey) e
 * suporte a resolução automática de índices.
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Query
 * @since 1.0.0
 */
class Builder extends BaseBuilder
{
    /**
     * Model instance associated with this query (for index resolution).
     * 
     * Instância do model Eloquent associado à query, usado pelo
     * Grammar para resolver índices automaticamente.
     *
     * @var DynamoDbModel|null
     */
    protected ?DynamoDbModel $model = null;

    /**
     * Set the model instance.
     * 
     * Associa um model Eloquent à query para permitir resolução
     * automática de índices (GSI/LSI).
     *
     * @param DynamoDbModel $model Model DynamoDB
     * 
     * @return self Retorna a própria instância para method chaining
     * 
     * @since 1.0.0
     */
    public function setModel(DynamoDbModel $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Get the model instance.
     * 
     * Retorna o model Eloquent associado à query.
     *
     * @return DynamoDbModel|null Model associado ou null
     * 
     * @since 1.0.0
     */
    public function getModel(): ?DynamoDbModel
    {
        return $this->model;
    }

    /**
     * Paginate the given query using cursor-based pagination.
     * 
     * Implementa paginação por cursor usando LastEvaluatedKey do DynamoDB.
     * DynamoDB não suporta OFFSET tradicional, apenas paginação sequencial
     * através de cursores. O cursor é codificado em base64 e contém o
     * LastEvaluatedKey necessário para buscar a próxima página.
     *
     * @param int $perPage Número de itens por página (padrão: 15)
     * @param array $columns Colunas a selecionar (não usado, mantido por compatibilidade)
     * @param string $cursorName Nome do parâmetro do cursor na query string (padrão: 'cursor')
     * @param string|null $cursor Cursor codificado da página atual (se null, busca da query string)
     * 
     * @return \Illuminate\Contracts\Pagination\Paginator Paginator com cursor para próxima página
     * 
     * @example
     * // Primeira página
     * $users = User::where('status', 'active')->simplePaginate(10);
     * 
     * // Próxima página (cursor automático via query string)
     * // URL: /users?cursor=eyJpZCI6InVzZXIxMjMiLCJjcmVhdGVkX2F0IjoxNjM...
     * $users = User::where('status', 'active')->simplePaginate(10);
     * 
     * // Com cursor explícito
     * $users = User::where('status', 'active')->simplePaginate(10, ['*'], 'cursor', $nextCursor);
     * 
     * @since 1.0.0
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

        // Adicionar ExclusiveStartKey se houver cursor
        if ($lastEvaluatedKey) {
            $params['ExclusiveStartKey'] = $this->connection->getMarshaler()->marshalItem($lastEvaluatedKey);
        }

        // Buscar um item a mais para saber se há próxima página
        $params['Limit'] = $perPage + 1;

        // Executar a operação no DynamoDB
        try {
            $client = $this->connection->getDynamoDbClient();
            $marshaler = $this->connection->getMarshaler();

            if ($operation === 'Query') {
                // Marshal ExpressionAttributeValues
                if (isset($params['ExpressionAttributeValues']) && !empty($params['ExpressionAttributeValues'])) {
                    $params['ExpressionAttributeValues'] = $marshaler->marshalItem($params['ExpressionAttributeValues']);
                }
                $result = $client->query($params);
            } else {
                // Scan
                if (isset($params['ExpressionAttributeValues']) && !empty($params['ExpressionAttributeValues'])) {
                    $params['ExpressionAttributeValues'] = $marshaler->marshalItem($params['ExpressionAttributeValues']);
                }
                $result = $client->scan($params);
            }

            // Unmarshal items
            $items = array_map(
                fn($item) => (object) $marshaler->unmarshalItem($item),
                $result['Items'] ?? []
            );

            // Verificar se há mais páginas
            $hasMorePages = count($items) > $perPage;
            
            // Remover o item extra se houver
            if ($hasMorePages) {
                array_pop($items);
            }

            // Gerar next cursor se houver mais páginas
            $nextCursor = null;
            if ($hasMorePages && isset($result['LastEvaluatedKey'])) {
                $unmarshaledKey = $marshaler->unmarshalItem($result['LastEvaluatedKey']);
                $nextCursor = base64_encode(json_encode($unmarshaledKey));
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
}

