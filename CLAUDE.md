# Lead Certo — Regras de Desenvolvimento

## Stack

- Laravel 13 · PHP 8.4 · MySQL 8 · Alpine.js v3 · Tailwind CSS
- VPS: `app.leadcerto.app.br` · SSH: `root@103.199.186.134` · Chave: `~/.ssh/leadcerto_vps`
- Repositório GitHub: `https://github.com/leadcerto/app-lead-certo.git`

## Fluxo obrigatório de deploy

**NUNCA altere arquivos diretamente na VPS.** O fluxo é sempre:

```
local → git commit → git push origin main → VPS: git pull
```

### Sequência de deploy

```bash
# 1. Commit local
git add <arquivos>
git commit -m "mensagem"

# 2. Push para GitHub
git push origin main

# 3. Deploy na VPS
ssh -i ~/.ssh/leadcerto_vps root@103.199.186.134 \
  "cd /var/www/leadcerto && git pull origin main && php artisan config:cache && php artisan route:cache && php artisan view:cache"
```

### Por que isso importa

Qualquer arquivo criado ou editado diretamente na VPS fica "fora do git". Na próxima vez que se fizer `git pull`, o pull falha por conflito — ou pior, `git clean -fd` remove o arquivo sem aviso. Isso causou perda de código em produção (junho 2025).

### Antes de qualquer pull na VPS

```bash
# Verificar se há arquivos não rastreados
git status

# Se houver: adicionar ao git local ANTES de dar pull
```

## Convenções do projeto

### Telefones

Formato canônico: `55DDXXXXXXXX` (sem espaços, sem hífen, sem parênteses).  
Celular: 13 dígitos. Fixo: 12 dígitos.

Comando para normalizar/mesclar duplicatas:
```bash
php artisan contatos:normalizar-telefones
```

### Multi-tenant

Todos os models de tenant usam `TenantScope` como global scope. Nunca fazer queries globais sem considerar o escopo do tenant.

Exceção: `Contato` é global (compartilhado entre tenants), isolado pelo `VinculoContatoTenant`.

### Models com tabelas explícitas

- `SequenciaMensagem` → `$table = 'sequencia_mensagens'`
- `Sequencia` → `$table = 'sequencias'`

Sempre declarar `$table` quando o nome do model em snake_case pluralizado pelo Laravel não bater com o nome real da tabela.

### Queue

Driver: `database`. Jobs com delay usam `->delay(now()->addSeconds(N))`.

Rodar workers na VPS:
```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

### Rotas

- `api/painel/*` — JSON API (retorna `JsonResponse`)
- Rotas web — retornam views

### Nomes de rotas importantes

| Rota | Controller | View |
|------|-----------|------|
| `kanban` | `KanbanController@view` | `kanban.index` |
| `kanban.config` | — | `kanban.config` |
| `kanban.variaveis` | — | `kanban.variaveis` |
| `contatos` | `ContatosController@view` | `contatos.index` |
| `contatos.importar` | `ContatosController@importar` | `contatos.importar` |
| `sequencia` | — | removida (usar `kanban.config`) |
