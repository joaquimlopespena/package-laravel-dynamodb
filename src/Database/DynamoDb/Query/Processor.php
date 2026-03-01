<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Query;

use Illuminate\Database\Query\Processors\Processor as BaseProcessor;
use Illuminate\Database\Query\Builder;

/**
 * DynamoDB Query Processor Class.
 * 
 * Processa resultados de queries DynamoDB e converte para o formato
 * esperado pelo Laravel. Gerencia agregações (count, sum, etc) e
 * conversão de tipos.
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Query
 * @since 1.0.0
 */
class Processor extends BaseProcessor
{
    /**
     * Process the results of a "select" query.
     * 
     * Processa os resultados retornados pela Connection. Se for uma query
     * de agregação (count, sum, etc), processa usando processAggregate.
     * Caso contrário, retorna os resultados já processados pela Connection.
     *
     * @param \Illuminate\Database\Query\Builder $query Query builder original
     * @param array $results Resultados retornados pela Connection
     * 
     * @return array Array de resultados processados
     * 
     * @example
     * // Query normal
     * $results = $processor->processSelect($query, $rawResults);
     * // Retorna array de objetos
     * 
     * // Query com count
     * $results = $processor->processSelect($query->count(), $rawResults);
     * // Retorna [(object)['aggregate' => 42]]
     * 
     * @since 1.0.0
     */
    public function processSelect($query, $results)
    {
        // Se for uma query de agregação (count, sum, etc)
        if (! is_null($query->aggregate)) {
            return $this->processAggregate($query, $results);
        }

        // Os resultados já vêm processados da Connection
        return $results;
    }

    /**
     * Process the results of an aggregate query.
     * 
     * Processa resultados de queries de agregação (count, sum, avg, min, max).
     * DynamoDB retorna Count quando usa Select=COUNT, que é convertido para
     * o formato esperado pelo Laravel com propriedade 'aggregate'.
     *
     * @param \Illuminate\Database\Query\Builder $query Query builder com agregação
     * @param array $results Resultados do DynamoDB
     * 
     * @return array Array com um objeto contendo propriedade 'aggregate'
     * 
     * @example
     * // DynamoDB retorna: [(object)['Count' => 42]]
     * // Processor converte para: [(object)['aggregate' => 42]]
     * 
     * @since 1.0.0
     */
    protected function processAggregate(Builder $query, $results)
    {
        // Se o resultado vier com Select COUNT do DynamoDB
        if (isset($results[0]) && isset($results[0]->Count)) {
            // Retornar no formato esperado pelo Laravel (com propriedade 'aggregate')
            return [(object) ['aggregate' => (int) $results[0]->Count]];
        }

        // Fallback: contar itens retornados ou retornar formato padrão
        if (empty($results)) {
            return [(object) ['aggregate' => 0]];
        }

        // Se já vier no formato correto, retornar
        if (isset($results[0]) && isset($results[0]->aggregate)) {
            return $results;
        }

        // Contar resultados para count()
        return [(object) ['aggregate' => count($results)]];
    }


}
