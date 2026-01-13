# 🔄 Compatibilidade com Código Legado e Testes

Este documento explica como o package lida com código legado e como testes podem afetar o pacote instalado.

## 📁 Renomeação do Arquivo de Configuração

O arquivo de configuração foi renomeado de `dynamodb.php` para `database-dynamodb.php` para evitar conflitos e seguir convenções do Laravel.

### ✅ Compatibilidade Retroativa

O package **mantém compatibilidade total** com código legado:

- ✅ Suporta `config/database-dynamodb.php` (novo nome)
- ✅ Suporta `config/dynamodb.php` (nome antigo - legado)
- ✅ O ServiceProvider detecta automaticamente qual arquivo existe
- ✅ Prioridade: `database-dynamodb.php` > `dynamodb.php` > config padrão do package

### 🔍 Como Funciona

O `DynamoDbServiceProvider` verifica os arquivos nesta ordem:

1. **Primeiro:** Procura `config/database-dynamodb.php` (novo)
2. **Segundo:** Procura `config/dynamodb.php` (legado)
3. **Terceiro:** Usa a configuração padrão do package

```php
// No ServiceProvider
if (file_exists(config_path('database-dynamodb.php'))) {
    // Usa novo nome
} elseif (file_exists(config_path('dynamodb.php'))) {
    // Usa nome antigo (legado)
} else {
    // Usa config padrão do package
}
```

## 🔧 Código Legado

### O que NÃO precisa mudar:

- ✅ **Models:** Continuam funcionando normalmente
  ```php
  protected $connection = 'dynamodb'; // Ainda funciona!
  ```

- ✅ **Conexões:** O ServiceProvider cria automaticamente um alias `dynamodb` que aponta para a conexão padrão

- ✅ **Arquivo de config existente:** Se você já tem `config/dynamodb.php`, ele continuará funcionando

### Migração Gradual (Opcional)

Se quiser migrar para o novo nome:

1. **Publicar nova configuração:**
   ```bash
   php artisan vendor:publish --provider="Joaquim\LaravelDynamoDb\DynamoDbServiceProvider" --tag="dynamodb-config"
   ```

2. **Copiar configurações do arquivo antigo:**
   ```bash
   cp config/dynamodb.php config/database-dynamodb.php
   ```

3. **Remover arquivo antigo (quando estiver seguro):**
   ```bash
   rm config/dynamodb.php
   ```

> **Importante:** Você pode manter ambos os arquivos durante a transição. O package sempre usará `database-dynamodb.php` se ambos existirem.

## 🧪 Testes e o Pacote Instalado

### Instalação via Symlink (Desenvolvimento)

Quando você instala o package localmente via `composer require joaquim/laravel-dynamodb:@dev`, o Composer cria um **symlink** em `vendor/joaquim/laravel-dynamodb/` que aponta para `package-laravel-dynamodb/`.

### ⚠️ Impacto nos Testes

**IMPORTANTE:** Como o package está instalado via symlink, **qualquer mudança no código do package afeta imediatamente o projeto que o usa**, incluindo testes!

#### ✅ Vantagens:

- Mudanças no package são refletidas instantaneamente
- Não precisa reinstalar o package após cada alteração
- Ideal para desenvolvimento e testes

#### ⚠️ Cuidados:

1. **Testes podem quebrar se você modificar o package:**
   - Se você alterar o código do package durante o desenvolvimento
   - Os testes que dependem do comportamento antigo podem falhar
   - Sempre teste suas mudanças antes de commitar

2. **Cache do Laravel:**
   ```bash
   # Limpar cache após mudanças no package
   php artisan config:clear
   php artisan cache:clear
   ```

3. **Autoload do Composer:**
   ```bash
   # Recarregar autoload após mudanças significativas
   composer dump-autoload
   ```

### 🧪 Boas Práticas para Testes

1. **Isolar testes do package:**
   ```php
   // Em seus testes, você pode mockar o package se necessário
   $this->mock(DynamoDbConnection::class, function ($mock) {
       $mock->shouldReceive('query')->andReturn(...);
   });
   ```

2. **Usar ambiente de teste isolado:**
   ```env
   # .env.testing
   DYNAMODB_ENDPOINT=http://localhost:8000
   DYNAMODB_CONNECTION=local
   ```

3. **Limpar cache antes dos testes:**
   ```php
   // Em TestCase.php
   protected function setUp(): void
   {
       parent::setUp();
       Artisan::call('config:clear');
   }
   ```

### 🔄 Quando Publicar no Packagist

Quando o package for publicado no Packagist:

- O symlink será substituído por uma instalação normal
- Mudanças no código fonte não afetarão mais projetos instalados
- Será necessário atualizar a versão e fazer `composer update`

## 📋 Resumo

### Código Legado:
- ✅ **Totalmente compatível** - não precisa mudar nada
- ✅ Suporta `dynamodb.php` e `database-dynamodb.php`
- ✅ Models e conexões continuam funcionando

### Testes:
- ⚠️ **Cuidado:** Mudanças no package afetam testes imediatamente (symlink)
- ✅ Limpar cache após mudanças: `php artisan config:clear`
- ✅ Usar ambiente de teste isolado
- ✅ Testar mudanças antes de commitar

### Migração:
- 🔄 Opcional e gradual
- 🔄 Pode manter ambos os arquivos durante transição
- 🔄 Prioridade: `database-dynamodb.php` > `dynamodb.php`

