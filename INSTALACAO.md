# 📦 Instalação do Package

## ✅ Package Pronto!

O package está completo em `package-laravel-dynamodb/` e pronto para ser instalado.

---

## 🚀 Instalação Local (Desenvolvimento)

### 1. Instalar via Composer

O projeto Laravel já está configurado com path repository. Execute:

```bash
composer require joaquim/laravel-dynamodb:@dev
```

Isso cria um symlink em `vendor/joaquim/laravel-dynamodb/` apontando para `package-laravel-dynamodb/`.

### 2. Publicar Configuração

```bash
php artisan vendor:publish --provider="Joaquim\LaravelDynamoDb\DynamoDbServiceProvider" --tag="dynamodb-config"
```

### 3. Configurar `.env`

```env
DYNAMODB_ENDPOINT=http://localhost:8000
AWS_ACCESS_KEY_ID=dummy
AWS_SECRET_ACCESS_KEY=dummy
AWS_DEFAULT_REGION=us-east-1
```

### 4. Pronto! 🎉

---

## 📁 Estrutura do Package

```
package-laravel-dynamodb/
├── composer.json              # Configuração do package
├── LICENSE                    # MIT License
├── README.md                  # Documentação
├── .gitignore                 # Arquivos ignorados
├── config/
│   └── dynamodb.php          # Configuração padrão
└── src/
    ├── DynamoDbServiceProvider.php
    └── Database/
        └── DynamoDb/
            ├── Connection/    # DynamoDbConnection
            ├── Connector/     # DynamoDbConnector
            ├── Query/         # Builder, Grammar, Processor
            ├── Eloquent/      # Model base
            └── Traits/        # HasDynamoDbKeys
```

---

## 🔧 Após Instalação

O package será disponibilizado em:

```
vendor/joaquim/laravel-dynamodb/
```

E você pode usar:

```php
use Joaquim\LaravelDynamoDb\Eloquent\Model;
use Joaquim\LaravelDynamoDb\Traits\HasDynamoDbKeys;
```

---

## 📦 Publicar no Packagist (Futuro)

1. Criar repositório Git
2. Fazer commit
3. Submeter no Packagist.org
4. Instalar: `composer require joaquim/laravel-dynamodb`

---

**Package pronto para uso! 🚀**

