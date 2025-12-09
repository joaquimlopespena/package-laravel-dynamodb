<?php

namespace Joaquim\LaravelDynamoDb\Database\DynamoDb\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model as DynamoDbModel;

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
}

