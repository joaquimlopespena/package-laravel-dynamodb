# Laravel DynamoDB Driver

Um driver completo e otimizado para Amazon DynamoDB no Laravel, com suporte a Eloquent, resolução automática de índices, ordenação nativa e muito mais.

## 📋 Características

- ✅ **Eloquent ORM Support** - Use modelos Eloquent normalmente
- ✅ **Resolução Automática de Índices** - Detecta e usa GSI/LSI automaticamente
- ✅ **Query Optimization** - Usa `KeyConditionExpression` em vez de `FilterExpression` quando possível
- ✅ **OrderBy Nativo** - Suporte a ordenação pelo Sort Key dos índices
- ✅ **Paginação Automática** - Paginação eficiente com `LastEvaluatedKey`
- ✅ **ProjectionExpression** - Seleção de atributos específicos
- ✅ **Cache de Metadados** - Cache de `DescribeTable` para melhor performance
- ✅ **DynamoDB Local Support** - Suporte completo para DynamoDB Local
- ✅ **Configuração Flexível** - Fácil troca entre ambientes (local/AWS)

## 🚀 Instalação

### Via Composer

```bash
composer require joaquim/laravel-dynamodb
```

### Configuração

Publique o arquivo de configuração:

```bash
php artisan vendor:publish --tag=dynamodb-config
```

Isso criará o arquivo `config/database-dynamodb.php`.

## ⚙️ Configuração

### Arquivo de Configuração

O arquivo `config/database-dynamodb.php` permite configurar múltiplas conexões:

```php
return [
    'default' => env('DYNAMODB_CONNECTION', 'local'),
    
    'on_connection' => env('DYNAMODB_CONNECTION', 'local'),
    
    'connections' => [
        'aws' => [
            'driver' => 'dynamodb',
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
        ],
        
        'local' => [
            'driver' => 'dynamodb',
            'region' => env('DYNAMODB_REGION', 'us-east-1'),
            'endpoint' => env('DYNAMODB_ENDPOINT', 'http://localhost:8000'),
            'key' => env('DYNAMODB_ACCESS_KEY_ID', 'AKIAIOSFODNN7EXAMPLE'),
            'secret' => env('DYNAMODB_SECRET_ACCESS_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'),
        ],
    ],
];
```

### Variáveis de Ambiente

No arquivo `.env`:

```env
# Para usar DynamoDB Local
DYNAMODB_CONNECTION=local
DYNAMODB_ENDPOINT=http://localhost:8000
DYNAMODB_REGION=us-east-1

# Para usar AWS DynamoDB
DYNAMODB_CONNECTION=aws
AWS_DEFAULT_REGION=us-east-1
AWS_ACCESS_KEY_ID=your-key
AWS_SECRET_ACCESS_KEY=your-secret
```

## 📖 Uso Básico

### Criando um Model

```php
<?php

namespace App\Models;

use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model;

class Cliente extends Model
{
    protected $connection = 'dynamodb';
    protected $table = 'clientes';
    
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    
    // Partition Key
    protected $partitionKey = 'id';
    
    // Sort Key (opcional)
    protected $sortKey = null;
    
    // Global Secondary Indexes (GSI)
    protected $gsiIndexes = [
        'email-index' => [
            'partition_key' => 'email',
            'sort_key' => null,
            'projection_type' => 'ALL',
        ],
        'status-nome-index' => [
            'partition_key' => 'status',
            'sort_key' => 'nome',
            'projection_type' => 'ALL',
        ],
    ];
    
    protected $fillable = ['id', 'nome', 'email', 'status'];
}
```

### Operações Básicas

```php
// Criar
$cliente = Cliente::create([
    'id' => '123',
    'nome' => 'João Silva',
    'email' => 'joao@example.com',
    'status' => 'ativo'
]);

// Buscar por ID
$cliente = Cliente::find('123');

// Buscar usando índice GSI
$cliente = Cliente::where('email', 'joao@example.com')->first();

// Buscar múltiplos com filtros
$clientes = Cliente::where('status', 'ativo')
    ->orderBy('nome', 'asc')
    ->get();

// Atualizar
$cliente->nome = 'João Santos';
$cliente->save();

// Deletar
$cliente->delete();
```

## 🎯 Funcionalidades Avançadas

### Resolução Automática de Índices

O pacote detecta automaticamente o melhor índice para usar baseado nas condições `where`:

```php
// Usa o índice 'status-nome-index' automaticamente
$clientes = Cliente::where('status', 'ativo')
    ->orderBy('nome', 'asc')
    ->get();
```

### OrderBy

O `orderBy` funciona nativamente quando o campo é o Sort Key do índice usado:

```php
// ✅ Funciona: 'nome' é Sort Key do índice 'status-nome-index'
$clientes = Cliente::where('status', 'ativo')
    ->orderBy('nome', 'desc')
    ->get();

// ⚠️ Não funciona nativamente: 'created_at' não é Sort Key deste índice
// (seria necessário usar outro índice ou ordenar em memória)
```

### Paginação

```php
// Simple pagination (recomendado para DynamoDB)
$clientes = Cliente::where('status', 'ativo')
    ->simplePaginate(20);
```

### Seleção de Atributos Específicos

```php
// Usa ProjectionExpression para retornar apenas campos específicos
$clientes = Cliente::select(['id', 'nome', 'email'])
    ->where('status', 'ativo')
    ->get();
```

### Count Otimizado

```php
// Count usa Select COUNT do DynamoDB (mais eficiente)
$total = Cliente::where('status', 'ativo')->count();
```

## 🏗️ Estrutura de Índices

### Global Secondary Index (GSI)

```php
protected $gsiIndexes = [
    'nome-do-indice' => [
        'partition_key' => 'campo_partition',
        'sort_key' => 'campo_sort', // opcional
        'projection_type' => 'ALL', // ou 'KEYS_ONLY', 'INCLUDE'
    ],
];
```

### Local Secondary Index (LSI)

```php
// LSI só funciona se você tiver Sort Key na tabela principal
protected $sortKey = 'created_at'; // Sort Key da tabela principal

protected $lsiIndexes = [
    'nome-do-indice-lsi' => [
        'sort_key' => 'outro_campo',
        'projection_type' => 'ALL',
    ],
];
```

## 🔍 Operações Suportadas

### GetItem

Quando você busca por Partition Key (e Sort Key, se aplicável):

```php
$cliente = Cliente::find('123');
```

### Query

Quando há condições que podem usar índices:

```php
// Usa Query com índice GSI
$clientes = Cliente::where('status', 'ativo')->get();

// Usa Query com ordenação
$clientes = Cliente::where('status', 'ativo')
    ->orderBy('nome', 'asc')
    ->get();
```

### Scan

Quando não há índices disponíveis (menos eficiente):

```php
// Usa Scan (com warning no log em debug mode)
$clientes = Cliente::where('nome', 'like', '%silva%')->get();
```

## 📝 Notas Importantes

### Limitações do DynamoDB

1. **OrderBy**: Só funciona com o Sort Key do índice usado na query
2. **Scan**: Menos eficiente que Query - tente sempre usar índices
3. **Paginação**: Use `simplePaginate()` para melhor performance
4. **Count**: Pode ser caro em tabelas grandes (considere cache)

### Boas Práticas

1. **Use Índices**: Sempre que possível, crie GSI para campos usados em filtros
2. **Evite Scan**: Priorize condições que usam índices
3. **Cache Counts**: Para contagens totais, considere cache
4. **ProjectionExpression**: Use `select()` para reduzir transferência de dados

## 🐛 Debug e Logs

Com `APP_DEBUG=true`, o pacote registra logs úteis:

- Queries usando índices
- Warnings quando usa Scan (ineficiente)
- Informações de performance

## 📚 Exemplos Completos

### Model Completo

```php
<?php

namespace App\Models;

use Joaquim\LaravelDynamoDb\Database\DynamoDb\Eloquent\Model;

class Produto extends Model
{
    protected $connection = 'dynamodb';
    protected $table = 'produtos';
    
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $partitionKey = 'id';
    protected $sortKey = null;
    
    protected $gsiIndexes = [
        'sku-index' => [
            'partition_key' => 'sku',
            'sort_key' => null,
            'projection_type' => 'ALL',
        ],
        'categoria-preco-index' => [
            'partition_key' => 'categoria_id',
            'sort_key' => 'preco',
            'projection_type' => 'ALL',
        ],
    ];
    
    protected $fillable = [
        'id', 'sku', 'nome', 'categoria_id', 'preco', 'estoque'
    ];
    
    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'estoque' => 'integer',
        ];
    }
    
    // Método helper usando índice
    public static function buscarPorSku(string $sku): ?self
    {
        return static::where('sku', $sku)->first();
    }
    
    // Método helper com ordenação
    public static function buscarPorCategoria(string $categoriaId, string $ordem = 'asc')
    {
        return static::where('categoria_id', $categoriaId)
            ->orderBy('preco', $ordem)
            ->get();
    }
}
```

### Controller Example

```php
<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $query = Cliente::query();
        
        // Filtros que usam índices (prioridade)
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('email')) {
            $query->where('email', $request->email);
        }
        
        // OrderBy (só funciona se o campo for Sort Key do índice usado)
        if ($request->filled('sort_by') && $request->filled('sort_order')) {
            $query->orderBy($request->sort_by, $request->sort_order);
        }
        
        $clientes = $query->simplePaginate(20);
        
        return view('clientes.index', compact('clientes'));
    }
}
```

## 🤝 Contribuindo

Contribuições são bem-vindas! Por favor:

1. Fork o projeto
2. Crie uma branch para sua feature (`git checkout -b feature/AmazingFeature`)
3. Commit suas mudanças (`git commit -m 'Add some AmazingFeature'`)
4. Push para a branch (`git push origin feature/AmazingFeature`)
5. Abra um Pull Request

## 📄 Licença

Este pacote é open-source e está disponível sob a licença [MIT License](LICENSE).

## 🙏 Agradecimentos

- Laravel Framework
- AWS SDK for PHP
- Comunidade DynamoDB

## 📞 Suporte

Para questões, problemas ou sugestões, por favor abra uma issue no repositório.

---

**Desenvolvido com ❤️ para a comunidade Laravel**
