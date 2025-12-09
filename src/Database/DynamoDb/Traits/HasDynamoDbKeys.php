<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Traits;

trait HasDynamoDbKeys
{
    /**
     * Get the partition key value.
     *
     * @return mixed
     */
    public function getPartitionKeyValue()
    {
        $key = $this->getPartitionKey();
        return $this->getAttribute($key);
    }

    /**
     * Get the sort key value.
     *
     * @return mixed|null
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
     * @return bool
     */
    public function hasCompositeKey()
    {
        return $this->getSortKey() !== null;
    }

    /**
     * Get the primary key value(s).
     *
     * @return array|string|int
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

