# Laravel DynamoDB Driver

DynamoDB driver for Laravel with automatic index resolution and Eloquent support.

## 📦 Instalação

### Via Composer (quando publicado no Packagist):

```bash
composer require joaquim/laravel-dynamodb
```

### Instalação Local (desenvolvimento):

1. Adicione ao `composer.json` do seu projeto Laravel:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../package-laravel-dynamodb"
        }
    ],
    "require": {
        "joaquim/laravel-dynamodb": "@dev"
    }
}
```

2. Execute:

```bash
composer require joaquim/laravel-dynamodb:@dev
```

3. Publicar configuração:

```bash
php artisan vendor:publish --provider="Joaquim\LaravelDynamoDb\DynamoDbServiceProvider" --tag="dynamodb-config"
```

## ⚙️ Configuração

### 1. Configurar `.env`:

```env
# DynamoDB Local (desenvolvimento)
DYNAMODB_ENDPOINT=http://localhost:8000
AWS_ACCESS_KEY_ID=dummy
AWS_SECRET_ACCESS_KEY=dummy
AWS_DEFAULT_REGION=us-east-1

# Ou AWS Real (produção)
# DYNAMODB_ENDPOINT=
# AWS_ACCESS_KEY_ID=your_key
# AWS_SECRET_ACCESS_KEY=your_secret
# AWS_DEFAULT_REGION=us-east-1
```

### 2. Adicionar conexão no `config/database.php`:

```php
'connections' => [
    'dynamodb' => [
        'driver' => 'dynamodb',
        'table' => env('DYNAMODB_TABLE', 'default'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'endpoint' => env('DYNAMODB_ENDPOINT'),
    ],
],
```

## 🚀 Uso

### Criar Model:

```php
<?php

namespace App\Models;

use Joaquim\LaravelDynamoDb\Eloquent\Model;
use Joaquim\LaravelDynamoDb\Traits\HasDynamoDbKeys;

class User extends Model
{
    use HasDynamoDbKeys;

    protected $connection = 'dynamodb';
    protected $table = 'users';
    
    protected $partitionKey = 'id';
    protected $sortKey = null; // Simple Key

    protected $gsiIndexes = [
        'email-index' => [
            'partition_key' => 'email',
        ],
    ];
}
```

### Usar:

```php
// Criar
User::create(['id' => '123', 'name' => 'João', 'email' => 'joao@test.com']);

// Buscar
$user = User::find('123');
$user = User::where('email', 'joao@test.com')->first();

// Atualizar
$user->update(['name' => 'João Silva']);

// Deletar
$user->delete();
```

## 📋 Requisitos

- PHP >= 8.2
- Laravel >= 12.0
- AWS SDK for PHP

## 📝 Licença

MIT

