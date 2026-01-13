# Laravel DynamoDB Driver

DynamoDB driver for Laravel with automatic index resolution and Eloquent support.

## 📦 Instalação

### Via Composer (quando publicado no Packagist):

```bash
composer require joaquim/laravel-dynamodb
```

### 🚀 Instalação Local (Desenvolvimento)

#### Passo 1: Adicionar repositório ao `composer.json`

Adicione ao `composer.json` do seu projeto Laravel:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../package-laravel-dynamodb"
        }
    ]
}
```

#### Passo 2: Instalar via Composer

Execute:

```bash
composer require joaquim/laravel-dynamodb:@dev
```

Isso cria um symlink em `vendor/joaquim/laravel-dynamodb/` apontando para o package local.

#### Passo 3: Publicar Configuração

```bash
php artisan vendor:publish --provider="Joaquim\LaravelDynamoDb\DynamoDbServiceProvider" --tag="dynamodb-config"
```

Isso cria o arquivo `config/dynamodb.php` com as conexões pré-configuradas.

#### Passo 4: Configurar `.env`

Para **DynamoDB Local** (desenvolvimento):
```env
DYNAMODB_ENDPOINT=http://localhost:8000
DYNAMODB_REGION=us-east-1
DYNAMODB_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
DYNAMODB_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
```

Para **AWS DynamoDB** (produção):
```env
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
```

#### Passo 5: Pronto! 🎉

O package está instalado e configurado. As conexões definidas em `config/dynamodb.php` são automaticamente mescladas com `config/database.php` pelo ServiceProvider, então **você não precisa modificar `config/database.php` manualmente!**

## ⚙️ Configuração

O arquivo `config/dynamodb.php` já vem com duas conexões pré-configuradas:

- **`aws`**: Para conexão com AWS DynamoDB real
- **`local`**: Para conexão com DynamoDB Local

Você pode editar essas conexões ou adicionar novas conforme necessário. O ServiceProvider automaticamente disponibiliza essas conexões no Laravel.

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

    protected $connection = 'local'; // ou 'aws' para produção
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

