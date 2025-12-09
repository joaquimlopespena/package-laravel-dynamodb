<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default DynamoDB Connection
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the DynamoDB connections below you wish
    | to use as your default connection.
    |
    */

    'default' => env('DYNAMODB_CONNECTION', 'dynamodb'),

    /*
    |--------------------------------------------------------------------------
    | DynamoDB Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for DynamoDB.
    | You can use this for both AWS DynamoDB and DynamoDB Local.
    |
    */

    'connections' => [
        'dynamodb' => [
            'driver' => 'dynamodb',
            'database' => env('DYNAMODB_TABLE', 'default'), // Usado pelo ConnectionFactory
            'table' => env('DYNAMODB_TABLE', 'default'), // Usado pelo nosso código
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => '', // Necessário pelo ConnectionFactory

            // Endpoint para DynamoDB Local (opcional)
            // Se não fornecido, usa AWS DynamoDB real
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],
    ],
];

