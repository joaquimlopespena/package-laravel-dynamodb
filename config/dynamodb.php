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

    'default' => env('DYNAMODB_CONNECTION', 'aws'),

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
        'aws' => [
            'driver' => 'dynamodb',
            'database' => env('DYNAMODB_TABLE', 'default'),
            'table' => env('DYNAMODB_TABLE', 'default'),
            'prefix' => '',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],

        'local' => [
            'driver' => 'dynamodb',
            'database' => env('DYNAMODB_TABLE', 'default'),
            'table' => env('DYNAMODB_TABLE', 'default'),
            'prefix' => '',
            'region' => env('DYNAMODB_REGION', 'us-east-1'),
            'endpoint' => env('DYNAMODB_ENDPOINT', 'http://localhost:8000'),
            'key' => env('DYNAMODB_ACCESS_KEY_ID', 'AKIAIOSFODNN7EXAMPLE'),
            'secret' => env('DYNAMODB_SECRET_ACCESS_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'),
        ],
    ],
];
