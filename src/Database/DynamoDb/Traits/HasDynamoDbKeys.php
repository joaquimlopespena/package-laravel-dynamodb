<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Traits;

/**
 * DynamoDB Keys Trait.
 * 
 * Trait para gerenciamento de chaves primárias compostas no DynamoDB.
 * Fornece métodos auxiliares para trabalhar com partition key e sort key,
 * facilitando acesso aos valores e verificação de chaves compostas.
 * 
 * Use este trait em models DynamoDB que precisam de métodos auxiliares
 * para manipulação de chaves compostas.
 * 
 * @package Joaquim\LaravelDynamoDb\Database\DynamoDb\Traits
 * @since 1.0.0
 */
trait HasDynamoDbKeys
{
    /**
     * Get the partition key value.
     * 
     * Retorna o valor da partition key do model atual.
     * A partition key determina a partição física onde o item está armazenado.
     *
     * @return mixed Valor da partition key (geralmente string ou int)
     * 
     * @example
     * $user = User::find('user123');
     * $partitionValue = $user->getPartitionKeyValue(); // 'user123'
     * 
     * @since 1.0.0
     */
    public function getPartitionKeyValue()
    {
        $key = $this->getPartitionKey();
        return $this->getAttribute($key);
    }

    /**
     * Get the sort key value.
     * 
     * Retorna o valor da sort key do model atual, se existir.
     * A sort key permite ordenação e range queries dentro de uma partition.
     *
     * @return mixed|null Valor da sort key ou null se não existir
     * 
     * @example
     * // Model com sort key
     * protected $sortKey = 'created_at';
     * 
     * $order = Order::find(['user_id' => 'user123', 'created_at' => 1234567890]);
     * $sortValue = $order->getSortKeyValue(); // 1234567890
     * 
     * // Model sem sort key
     * $user = User::find('user123');
     * $sortValue = $user->getSortKeyValue(); // null
     * 
     * @since 1.0.0
     */
    public function getSortKeyValue()
    {
        $key = $this->getSortKey();
        if ($key === null) {
            return null;
        }
        return $this->getAttribute($key);
    }

    /**
     * Check if model uses composite key.
     * 
     * Verifica se o model usa chave composta (partition key + sort key).
     * Útil para determinar comportamento em operações que dependem
     * do tipo de chave primária.
     *
     * @return bool True se usa chave composta (tem sort key)
     * 
     * @example
     * // Model com chave simples
     * protected $partitionKey = 'id';
     * $user->hasCompositeKey(); // false
     * 
     * // Model com chave composta
     * protected $partitionKey = 'user_id';
     * protected $sortKey = 'created_at';
     * $order->hasCompositeKey(); // true
     * 
     * @since 1.0.0
     */
    public function hasCompositeKey()
    {
        return $this->getSortKey() !== null;
    }

    /**
     * Get the primary key value(s).
     * 
     * Retorna o valor da chave primária. Para chaves simples (apenas partition key),
     * retorna o valor da partition key. Para chaves compostas (partition + sort),
     * retorna um array associativo com ambos os valores.
     * 
     * Sobrescreve o método padrão do Eloquent para suportar chaves compostas.
     *
     * @return array|string|int Valor da partition key ou array com partition e sort keys
     * 
     * @example
     * // Chave simples
     * $user = User::find('user123');
     * $key = $user->getKey(); // 'user123'
     * 
     * // Chave composta
     * $order = Order::find(['user_id' => 'user123', 'created_at' => 1234567890]);
     * $key = $order->getKey();
     * // ['user_id' => 'user123', 'created_at' => 1234567890]
     * 
     * @since 1.0.0
     */
    public function getKey()
    {
        if ($this->hasCompositeKey()) {
            return [
                $this->getPartitionKey() => $this->getPartitionKeyValue(),
                $this->getSortKey() => $this->getSortKeyValue(),
            ];
        }

        return $this->getPartitionKeyValue();
    }
}

