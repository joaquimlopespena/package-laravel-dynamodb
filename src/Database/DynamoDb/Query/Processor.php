<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Query;

use Illuminate\Database\Query\Processors\Processor as BaseProcessor;
use Illuminate\Database\Query\Builder;

class Processor extends BaseProcessor
{
    /**
     * Process the results of a "select" query.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param array $results
     * @return array
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
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $results
     * @return array
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
