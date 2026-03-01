<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent;

use Illuminate\Database\Eloquent\Builder as BaseBuilder;

/**
 * DynamoDB Eloquent Builder Class.
 * 
 * Extensão do Eloquent Builder que delega paginação para o
 * Query Builder customizado do DynamoDB, suportando paginação
 * por cursor (LastEvaluatedKey).
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent
 * @since 1.0.0
 */
class Builder extends BaseBuilder
{
    /**
     * Paginate the given query using cursor-based pagination for DynamoDB.
     * 
     * Implementa paginação por cursor específica para DynamoDB.
     * Sobrescreve o comportamento padrão do Eloquent Builder para
     * delegar ao Query Builder customizado que entende LastEvaluatedKey.
     * 
     * DynamoDB não suporta OFFSET tradicional, apenas paginação sequencial.
     *
     * @param int|null $perPage Número de itens por página
     * @param array|string $columns Colunas a selecionar (convertido para array)
     * @param string $cursorName Nome do parâmetro do cursor
     * @param string|null $cursor Cursor codificado da página atual
     * 
     * @return \Illuminate\Contracts\Pagination\Paginator Paginator com suporte a cursor
     * 
     * @example
     * // Eloquent
     * $users = User::where('status', 'active')->simplePaginate(15);
     * 
     * // Com cursor customizado
     * $users = User::simplePaginate(10, ['*'], 'page_cursor', $cursor);
     * 
     * @since 1.0.0
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
