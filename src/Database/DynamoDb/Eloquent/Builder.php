<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent;

use Illuminate\Database\Eloquent\Builder as BaseBuilder;

class Builder extends BaseBuilder
{
    /**
     * Paginate the given query using cursor-based pagination for DynamoDB.
     * 
     * DynamoDB não suporta OFFSET, então usamos LastEvaluatedKey (cursor).
     * Este método sobrescreve o comportamento padrão do Eloquent Builder
     * para delegar diretamente ao Query Builder customizado do DynamoDB.
     *
     * @param int|null $perPage
     * @param array|string $columns
     * @param string $cursorName
     * @param string|null $cursor
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = 15, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        // Garantir que columns seja array
        if (!is_array($columns)) {
            $columns = ['*'];
        }

        // Delegar diretamente para o Query Builder customizado do DynamoDB
        // que tem a implementação correta de paginação por cursor
        return $this->query->simplePaginate($perPage, $columns, $cursorName, $cursor);
    }
}
