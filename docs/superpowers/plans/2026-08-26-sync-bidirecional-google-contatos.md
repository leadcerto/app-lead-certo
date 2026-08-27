# Sync Bidirecional Google Contatos ↔ Lead Certo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o sync Google Contatos ↔ Lead Certo trazer de volta correções humanas feitas no Google mesmo quando o campo local já tem valor, sem nunca sobrescrever uma edição humana feita aqui sem revisão, sem nunca tocar em etiquetas/`contactGroups`, e com latência mínima pro lead inicial.

**Architecture:** Três colunas JSON novas em `vinculos_contato_tenant` rastreiam (a) o que já enviamos pro Google (linha de base), (b) quais campos foram editados por um humano aqui, (c) uma fila de conflito quando os dois lados divergem com edição humana dos dois. Um método central em `ContatoSyncService` decide o desfecho por campo e é reaproveitado tanto pelo cron (pull em lote) quanto por um job novo disparado na criação do vínculo (busca pontual, tempo real). O painel do Auditor generaliza pra mostrar/resolver qualquer campo pendente, não só nome.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL 8 (colunas JSON), Google People API.

**Spec:** `docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md`

## Global Constraints

- Nunca ler nem escrever `memberships`/`contactGroups` em nenhuma chamada NOVA ou MODIFICADA à Google People API. A leitura já existente de `memberships` em `GoogleService::PERSON_FIELDS` (usada por `ContatoSyncService::detectarTipoContato()`) é uso legítimo pré-existente e não deve ser removida nem estendida — só não pode ganhar companhia.
- `telefone` nunca entra na lógica de conflito/campo sincronizado — é a chave de identidade usada pra achar o `Contato`, tratado à parte.
- Campos cobertos pela lógica de conflito: `nome`, `sobrenome`, `empresa`, `email` — exatamente esses quatro, em todas as tasks.
- `PushContatoParaGoogleJob` (criação automática vinda de import de agenda) nunca marca `campos_editados_humano` — não é edição humana. Só grava a linha de base (`google_valores_enviados`).
- O job de busca em tempo real (Task 5) roda sem delay mas em background (fila `default`) — nunca bloqueia a resposta do webhook/app/formulário que criou o contato.
- `Contato::semNomeReal()` é o critério de "vazio" pro campo `nome` em toda comparação (placeholder "Sem Nome"/telefone repetido/vazio); os outros três campos usam `empty()` simples.
- Toda mudança em schema ou lógica de sync roda nos testes locais (`php artisan test`) antes de qualquer commit — sem rodar contra o banco de produção.

---

### Task 1: Schema — colunas JSON novas em `vinculos_contato_tenant`

**Files:**
- Create: `database/migrations/2026_08_26_000002_add_sync_bidirecional_fields_to_vinculos_contato_tenant_table.php`
- Modify: `app/Models/VinculoContatoTenant.php`
- Test: `tests/Feature/VinculoContatoTenantSyncFieldsTest.php`

**Interfaces:**
- Produces: colunas `google_valores_enviados` (JSON nullable), `campos_editados_humano` (JSON nullable), `campos_pendentes_auditoria` (JSON nullable) em `vinculos_contato_tenant`. `VinculoContatoTenant::$fillable` e `$casts` incluindo essas três como `array`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VinculoContatoTenantSyncFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_e_le_os_tres_campos_json_novos_como_array(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();

        $vinculo = VinculoContatoTenant::create([
            'contato_id'                 => $contato->id,
            'tenant_id'                  => $tenant->id,
            'google_valores_enviados'    => ['nome' => 'Marcia Souza'],
            'campos_editados_humano'     => ['nome' => '2026-08-26T10:00:00-03:00'],
            'campos_pendentes_auditoria' => ['empresa' => ['sugerido' => 'Fretes ABC', 'origem' => 'google']],
        ]);

        $vinculo->refresh();

        $this->assertSame(['nome' => 'Marcia Souza'], $vinculo->google_valores_enviados);
        $this->assertSame(['nome' => '2026-08-26T10:00:00-03:00'], $vinculo->campos_editados_humano);
        $this->assertSame(
            ['empresa' => ['sugerido' => 'Fretes ABC', 'origem' => 'google']],
            $vinculo->campos_pendentes_auditoria
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VinculoContatoTenantSyncFieldsTest`
Expected: FAIL — coluna `google_valores_enviados` não existe (ou não é mass-assignable).

- [ ] **Step 3: Criar a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design: docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md
 * seção 5. Generaliza google_given_name/nome_sugerido/auditoria_pendente (que
 * cobriam só o campo nome) pra os 4 campos sincronizados. As colunas antigas
 * saem numa migration separada, ao final do plano (Task 9), só depois que
 * todo call site tiver migrado pras novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->json('google_valores_enviados')->nullable()->after('google_etag');
            $table->json('campos_editados_humano')->nullable()->after('google_valores_enviados');
            $table->json('campos_pendentes_auditoria')->nullable()->after('campos_editados_humano');
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn(['google_valores_enviados', 'campos_editados_humano', 'campos_pendentes_auditoria']);
        });
    }
};
```

- [ ] **Step 4: Atualizar o model**

Em `app/Models/VinculoContatoTenant.php`, adicionar as três colunas ao `$fillable` e ao `$casts` como `'array'`:

```php
protected $casts = [
    'created_at'                 => 'datetime',
    'auditoria_pendente'         => 'boolean',
    'bloqueado_em'                => 'datetime',
    'google_valores_enviados'    => 'array',
    'campos_editados_humano'     => 'array',
    'campos_pendentes_auditoria' => 'array',
];

protected $fillable = [
    'contato_id',
    'tenant_id',
    'google_resource_name',
    'google_etag',
    'google_given_name',
    'nome_sugerido',
    'auditoria_pendente',
    'bloqueado_em',
    'google_valores_enviados',
    'campos_editados_humano',
    'campos_pendentes_auditoria',
];
```

(`google_given_name`/`nome_sugerido`/`auditoria_pendente` continuam no fillable até a Task 9 dropar as colunas — outras tasks deste plano ainda leem/escrevem nelas até serem migradas.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=VinculoContatoTenantSyncFieldsTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_26_000002_add_sync_bidirecional_fields_to_vinculos_contato_tenant_table.php app/Models/VinculoContatoTenant.php tests/Feature/VinculoContatoTenantSyncFieldsTest.php
git commit -m "feat(google-sync): colunas JSON novas em vinculos_contato_tenant"
```

---

### Task 2: Backfill conservador (migration de dados)

**Files:**
- Create: `database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php`
- Test: `tests/Feature/BackfillCamposEditadosHumanoTest.php`

**Interfaces:**
- Consumes: `Contato::semNomeReal()` (Task 1's schema), `VinculoContatoTenant` fillable/casts (Task 1).
- Produces: nenhuma interface nova — só efeito de dados. Task 3 (pull) depende deste backfill já ter rodado pra não sobrescrever dado real no primeiro sync pós-deploy.

**Por que uma migration e não um comando:** roda automaticamente no deploy (`deploy.sh` já faz `migrate --force`), garantindo que a Task 3 nunca rode em produção antes do backfill — não depende de alguém lembrar de rodar um `artisan` à parte.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillCamposEditadosHumanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_marca_como_humano_todo_campo_ja_preenchido_e_real(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'nome' => 'Marcia Souza', 'sobrenome' => 'Souza', 'empresa' => 'Fretes ABC', 'email' => 'm@x.com',
        ]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        // Roda de novo a migration específica do backfill isoladamente —
        // RefreshDatabase já rodou todas as migrations (incluindo esta) antes
        // do teste, então o dado acima foi criado DEPOIS do backfill já ter
        // rodado uma vez com a tabela vazia. Simula o cenário real (dado
        // existente antes do deploy) rodando o backfill de novo agora.
        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php', '--force' => true]);

        $vinculo->refresh();

        $this->assertArrayHasKey('nome', $vinculo->campos_editados_humano);
        $this->assertArrayHasKey('sobrenome', $vinculo->campos_editados_humano);
        $this->assertArrayHasKey('empresa', $vinculo->campos_editados_humano);
        $this->assertArrayHasKey('email', $vinculo->campos_editados_humano);
    }

    public function test_backfill_nao_marca_campo_vazio_ou_placeholder(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create([
            'nome' => 'Sem Nome', 'sobrenome' => null, 'empresa' => null, 'email' => null,
        ]);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        Artisan::call('migrate:refresh', ['--path' => 'database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php', '--force' => true]);

        $vinculo->refresh();

        $this->assertNull($vinculo->campos_editados_humano);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackfillCamposEditadosHumanoTest`
Expected: FAIL — a migration do backfill ainda não existe (`migrate:refresh --path` falha por arquivo não encontrado), ou os dois testes ficam vermelhos porque nada preenche `campos_editados_humano`.

- [ ] **Step 3: Criar a migration de backfill**

```php
<?php

use App\Models\Contato;
use App\Models\VinculoContatoTenant;
use Illuminate\Database\Migrations\Migration;

/**
 * Design: docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md
 * seção 8. Sem isso, o primeiro pull (Task 3) depois do deploy trataria todo
 * campo local já preenchido como "não-humano" e poderia sobrescrever dado
 * real com o que estiver no Google na hora. Marca como humano por segurança
 * — só entra na regra nova a partir daqui pra frente.
 */
return new class extends Migration
{
    private const CAMPOS = ['nome', 'sobrenome', 'empresa', 'email'];

    public function up(): void
    {
        VinculoContatoTenant::with('contato')
            ->whereNull('campos_editados_humano')
            ->chunkById(200, function ($vinculos) {
                foreach ($vinculos as $vinculo) {
                    $contato = $vinculo->contato;
                    if (! $contato) {
                        continue;
                    }

                    $campos = [];
                    foreach (self::CAMPOS as $campo) {
                        $valor = $contato->$campo;
                        if (empty($valor)) {
                            continue;
                        }
                        if ($campo === 'nome' && $contato->semNomeReal()) {
                            continue;
                        }
                        $campos[$campo] = now()->toIso8601String();
                    }

                    if ($campos) {
                        $vinculo->update(['campos_editados_humano' => $campos]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Backfill de dados — não há "desfazer" seguro sem saber quais linhas
        // tinham o campo preenchido antes de rodar. Intencionalmente vazio.
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackfillCamposEditadosHumanoTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_26_000003_backfill_campos_editados_humano_vinculos_contato_tenant.php tests/Feature/BackfillCamposEditadosHumanoTest.php
git commit -m "feat(google-sync): backfill conservador de campos_editados_humano"
```

---

### Task 3: `ContatoSyncService` — regra de conflito centralizada + pull dos 4 campos

**Files:**
- Modify: `app/Services/ContatoSyncService.php`
- Test: `tests/Feature/ContatoSyncServiceConflitoTest.php`

**Interfaces:**
- Produces: `ContatoSyncService::resolverCampoGoogle(Contato $contato, VinculoContatoTenant $vinculo, string $campo, ?string $valorGoogle): void` — método público, reaproveitado pela Task 5 (job de busca em tempo real). Aplica a regra da spec seção 6 pra UM campo: atualiza `$contato`, `$vinculo->google_valores_enviados[$campo]` e `$vinculo->campos_pendentes_auditoria[$campo]` conforme o caso, e persiste o `$vinculo` no final (`$vinculo->save()`) — quem chama não precisa salvar de novo.
- Consumes: `Contato::semNomeReal()` (já existe), `VinculoContatoTenant` (Task 1).

**Regra implementada (spec seção 6), por campo:**
1. `$valorGoogle` vazio/null → não faz nada (nunca apaga por ausência).
2. `$valorGoogle` bate com `$vinculo->google_valores_enviados[$campo] ?? null` → nada mudou lá, não faz nada.
3. Diferente da linha de base:
   - Campo NÃO em `$vinculo->campos_editados_humano` (ou local vazio/placeholder) → aceita: atualiza `$contato->$campo`, atualiza a linha de base pro novo valor.
   - Campo EM `campos_editados_humano` E valor local diverge de `$valorGoogle` → grava `campos_pendentes_auditoria[$campo] = ['sugerido' => $valorGoogle, 'origem' => 'google']`, NÃO sobrescreve o local, mas AINDA atualiza a linha de base (evita recriar a mesma pendência no próximo ciclo).
   - Campo EM `campos_editados_humano` mas valor local já bate com `$valorGoogle` → não é conflito de verdade, só atualiza a linha de base.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatoSyncServiceConflitoTest extends TestCase
{
    use RefreshDatabase;

    private function vinculo(array $contatoAttrs = [], array $vinculoAttrs = []): VinculoContatoTenant
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create($contatoAttrs);

        return VinculoContatoTenant::create(array_merge([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ], $vinculoAttrs));
    }

    public function test_aceita_correcao_do_google_quando_campo_local_nao_e_humano(): void
    {
        $vinculo = $this->vinculo(['empresa' => null]); // automático/vazio, nunca editado por humano

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->contato->refresh();
        $vinculo->refresh();
        $this->assertSame('Fretes ABC', $vinculo->contato->empresa);
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);
        $this->assertArrayNotHasKey('empresa', $vinculo->campos_pendentes_auditoria ?? []);
    }

    public function test_nao_sobrescreve_quando_humano_editou_local_e_valores_divergem(): void
    {
        $vinculo = $this->vinculo(
            ['empresa' => 'Transportes Silva'],
            ['campos_editados_humano' => ['empresa' => now()->toIso8601String()]]
        );

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->contato->refresh();
        $vinculo->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->empresa); // não mexeu
        $this->assertSame(
            ['sugerido' => 'Fretes ABC', 'origem' => 'google'],
            $vinculo->campos_pendentes_auditoria['empresa'] ?? null
        );
        // linha de base atualiza mesmo sem aplicar — evita recriar a pendência de novo
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);
    }

    public function test_nao_recria_pendencia_ja_existente_no_ciclo_seguinte(): void
    {
        $vinculo = $this->vinculo(
            ['empresa' => 'Transportes Silva'],
            [
                'campos_editados_humano'     => ['empresa' => now()->toIso8601String()],
                'google_valores_enviados'    => ['empresa' => 'Fretes ABC'], // já rodou uma vez
                'campos_pendentes_auditoria' => ['empresa' => ['sugerido' => 'Fretes ABC', 'origem' => 'google']],
            ]
        );

        $service = app(ContatoSyncService::class);
        // Ciclo seguinte do cron, mesmo valor do Google — não deve mudar nada
        $service->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->empresa);
        $this->assertSame(
            ['sugerido' => 'Fretes ABC', 'origem' => 'google'],
            $vinculo->campos_pendentes_auditoria['empresa']
        );
    }

    public function test_ausencia_no_google_nunca_apaga_campo_local(): void
    {
        $vinculo = $this->vinculo(['empresa' => 'Transportes Silva']);

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', null);

        $vinculo->contato->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->empresa);
    }

    public function test_campo_nome_usa_semnomereal_como_criterio_de_vazio(): void
    {
        $vinculo = $this->vinculo(['nome' => 'Sem Nome']);

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'nome', 'Rodrigo Alves');

        $vinculo->contato->refresh();
        $this->assertSame('Rodrigo Alves', $vinculo->contato->nome);
    }

    public function test_valor_igual_a_linha_de_base_nao_faz_nada(): void
    {
        $vinculo = $this->vinculo(
            ['empresa' => 'Fretes ABC'],
            ['google_valores_enviados' => ['empresa' => 'Fretes ABC']]
        );

        app(ContatoSyncService::class)->resolverCampoGoogle($vinculo->contato, $vinculo, 'empresa', 'Fretes ABC');

        $vinculo->refresh();
        $this->assertArrayNotHasKey('empresa', $vinculo->campos_pendentes_auditoria ?? []);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ContatoSyncServiceConflitoTest`
Expected: FAIL — `resolverCampoGoogle` não existe ainda.

- [ ] **Step 3: Implementar `resolverCampoGoogle()` em `ContatoSyncService`**

Adicionar este método público (os quatro campos usados por ele: `nome`, `sobrenome`, `empresa`, `email` — nunca `telefone`):

```php
    private const CAMPOS_SINCRONIZADOS = ['nome', 'sobrenome', 'empresa', 'email'];

    /**
     * Decide o desfecho de UM campo sincronizado comparando o valor vindo do
     * Google com a linha de base (o que nós mesmos enviamos por último) e com
     * o estado de edição humana local. Design:
     * docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md
     * seção 6. Reaproveitado pelo pull em lote (processarPessoa, abaixo) e
     * pelo job de busca em tempo real (EnriquecerContatoNovoViaGoogleJob).
     * Salva o $vinculo no final — quem chama não precisa salvar de novo.
     */
    public function resolverCampoGoogle(Contato $contato, VinculoContatoTenant $vinculo, string $campo, ?string $valorGoogle): void
    {
        $valorGoogle = trim((string) $valorGoogle);
        if ($valorGoogle === '') {
            return; // nunca interpreta ausência como "apagar aqui"
        }

        $linhaBase = $vinculo->google_valores_enviados[$campo] ?? null;
        if ($valorGoogle === $linhaBase) {
            return; // nada mudou lá desde nosso último envio
        }

        $editadoHumano = isset($vinculo->campos_editados_humano[$campo]);
        $valorLocal    = $contato->$campo;
        $localVazio    = $campo === 'nome' ? $contato->semNomeReal() : empty($valorLocal);

        $valoresEnviados = $vinculo->google_valores_enviados ?? [];
        $valoresEnviados[$campo] = $valorGoogle;

        if (! $editadoHumano || $localVazio) {
            // Aceita o valor do Google — não há edição humana local pra proteger
            $contato->update([$campo => $valorGoogle]);
            $vinculo->google_valores_enviados = $valoresEnviados;
            $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
            unset($pendentes[$campo]);
            $vinculo->campos_pendentes_auditoria = $pendentes ?: null;
            $vinculo->save();
            return;
        }

        if ((string) $valorLocal === $valorGoogle) {
            // Os dois convergiram pro mesmo valor por conta própria — não é conflito
            $vinculo->google_valores_enviados = $valoresEnviados;
            $vinculo->save();
            return;
        }

        // Humano local x valor diferente vindo do Google — vai pra auditoria,
        // mas a linha de base atualiza mesmo assim (evita recriar a mesma
        // pendência a cada ciclo do cron enquanto ninguém resolve).
        $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
        $pendentes[$campo] = ['sugerido' => $valorGoogle, 'origem' => 'google'];
        $vinculo->campos_pendentes_auditoria = $pendentes;
        $vinculo->google_valores_enviados    = $valoresEnviados;
        $vinculo->save();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ContatoSyncServiceConflitoTest`
Expected: PASS

- [ ] **Step 5: Cablear `resolverCampoGoogle()` no pull em lote**

Em `processarPessoa()`, o ramo "Telefone já existe" (linhas ~136-193 hoje) substitui o loop manual de merge (linhas 154-163) por uma chamada a `resolverCampoGoogle()` pra cada um dos 4 campos — o restante do método (tipo_contato, `dadosVinculo`, criação de `ContatoPendente` por similaridade baixa) fica igual. Trecho substituído:

```php
                    if ($similaridade >= self::LIMIAR_SIMILARIDADE || $existente->semNomeReal()) {
                        $vinculoExistente = VinculoContatoTenant::firstOrCreate(
                            ['contato_id' => $existente->id, 'tenant_id' => $tenantId],
                            $this->dadosVinculo($pessoa)
                        );

                        foreach (self::CAMPOS_SINCRONIZADOS as $campo) {
                            $this->resolverCampoGoogle($existente, $vinculoExistente, $campo, $dados[$campo] ?? null);
                        }

                        // Tipo detectado do Google sempre sobrepõe 'lead' (categoria padrão)
                        if ($tipoDetectado && ($existente->fresh()->tipo_contato === 'lead' || ! $existente->tipo_contato)) {
                            $existente->update(['tipo_contato' => $tipoDetectado]);
                        }

                        $vinculoExistente->update($this->dadosVinculo($pessoa));

                        $resultado['atualizados']++;
                    } else {
```

Note: `firstOrCreate` (em vez do `updateOrCreate` que existia antes) porque `resolverCampoGoogle()` já lê/escreve os campos JSON do `$vinculoExistente` — um `updateOrCreate` logo em seguida sobrescrevendo com `dadosVinculo()` apagaria o que acabou de ser gravado. Por isso o `->update($this->dadosVinculo($pessoa))` (só `google_resource_name`/`google_etag`/`google_given_name`) roda DEPOIS do loop, não antes.

- [ ] **Step 6: Rodar a suíte inteira de `ContatoSyncService` pra garantir que nada quebrou**

Run: `php artisan test --filter=ContatoSyncService`
Expected: PASS — inclui os testes já existentes de `ContatoSyncServiceSemNomeTest`.

- [ ] **Step 7: Commit**

```bash
git add app/Services/ContatoSyncService.php tests/Feature/ContatoSyncServiceConflitoTest.php
git commit -m "feat(google-sync): resolverCampoGoogle() centraliza a regra de conflito no pull"
```

---

### Task 4: Push — marcar edição humana e atualizar linha de base

**Files:**
- Modify: `app/Http/Controllers/Painel/ContatosController.php` (método `atualizarContato`, linhas ~873-949, e `sincronizarComGoogle`, linhas ~951-984)
- Modify: `app/Http/Controllers/Internal/ContatoController.php` (método `upsert`)
- Modify: `app/Jobs/PushContatoParaGoogleJob.php`
- Test: `tests/Feature/ContatosControllerSyncHumanoTest.php`
- Test: `tests/Feature/PushContatoParaGoogleJobLinhaBaseTest.php`

**Interfaces:**
- Consumes: `VinculoContatoTenant` colunas da Task 1, `GoogleService::enriquecerContato()`/`criarContato()` (já existem, sem mudança de assinatura).

**Mudanças:**

1. `ContatosController::atualizarContato()` — a regra de governança interna (nome de parceiro/SDR divergente do master) passa a gravar em `campos_pendentes_auditoria['nome'] = ['sugerido' => ..., 'origem' => 'humano_interno']` em vez de `nome_sugerido`/`auditoria_pendente`. Além disso, TODO campo salvo por um usuário privilegiado (dono/admin) marca `campos_editados_humano[$campo] = now()`.
2. `sincronizarComGoogle()` — depois do `enriquecerContato()` bem-sucedido, grava `google_valores_enviados[$campo]` pra cada campo que foi enviado.
3. `Internal/ContatoController::upsert()` — o mesmo padrão de conflito (pushName do WhatsApp divergente do master) migra pra `campos_pendentes_auditoria['nome'] = [..., 'origem' => 'whatsapp_pushname']`.
4. `PushContatoParaGoogleJob` — depois de `criarContato()` bem-sucedido, grava a linha de base inicial em `google_valores_enviados` com os campos que foram enviados (nome, sobrenome, empresa, email, conforme o `Contato` tinha na hora). **Não** marca `campos_editados_humano`.

- [ ] **Step 1: Write the failing test — push do painel marca humano e atualiza linha de base**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContatosControllerSyncHumanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_edicao_no_painel_marca_campo_editado_humano_e_atualiza_linha_de_base(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['etag' => 'etag-novo'], 200)]);

        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'dono']);
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['empresa' => null]);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123', 'google_etag' => 'etag-antigo',
        ]);

        $this->actingAs($user)
            ->putJson("/api/painel/contatos/{$contato->id}", ['empresa' => 'Fretes ABC'])
            ->assertOk();

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)->first();
        $this->assertArrayHasKey('empresa', $vinculo->campos_editados_humano);
        $this->assertSame('Fretes ABC', $vinculo->google_valores_enviados['empresa'] ?? null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ContatosControllerSyncHumanoTest`
Expected: FAIL — `campos_editados_humano` e `google_valores_enviados` ainda ficam vazios.

- [ ] **Step 3: Atualizar `ContatosController::atualizarContato()`**

Substituir o bloco de governança (linhas ~915-946 hoje):

```php
        // Regra de governança: nome editado por parceiro/SDR vai para auditoria
        // se o master já tiver um nome diferente. Dono e admin atualizam direto.
        $perfilPrivilegiado = in_array($request->user()->perfil ?? '', ['dono', 'admin']);
        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (
            ! $perfilPrivilegiado &&
            isset($dados['nome']) &&
            $contato->nome &&
            strtolower(trim($dados['nome'])) !== strtolower(trim($contato->nome))
        ) {
            if ($vinculo) {
                $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
                $pendentes['nome'] = ['sugerido' => $dados['nome'], 'origem' => 'humano_interno'];
                $vinculo->update(['campos_pendentes_auditoria' => $pendentes]);
            }

            unset($dados['nome']); // master intacto

            $contato->update($dados); // aplica outros campos (email, profissao, etc.)
            $this->sincronizarComGoogle($contato, $tenantId, $dados);

            return response()->json([
                'ok'         => true,
                'auditoria'  => true,
                'mensagem'   => 'Nome enviado para auditoria. Os demais dados foram salvos.',
            ]);
        }

        if ($vinculo) {
            $humano = $vinculo->campos_editados_humano ?? [];
            foreach (array_keys($dados) as $campo) {
                if (in_array($campo, ['nome', 'sobrenome', 'empresa', 'email'], true)) {
                    $humano[$campo] = now()->toIso8601String();
                }
            }
            if ($humano) {
                $vinculo->update(['campos_editados_humano' => $humano]);
            }
        }

        $contato->update($dados);
        $this->sincronizarComGoogle($contato, $tenantId, $dados);

        return response()->json(['ok' => true, 'auditoria' => false, 'contato' => $contato->fresh()]);
```

- [ ] **Step 4: Atualizar `sincronizarComGoogle()` pra gravar a linha de base**

```php
    private function sincronizarComGoogle(Contato $contato, int $tenantId, array $camposSalvos): void
    {
        $camposSincronizados = array_intersect(['nome', 'sobrenome', 'empresa', 'email'], array_keys($camposSalvos));
        if (! $camposSincronizados) {
            return;
        }

        $token = GoogleToken::where('tenant_id', $tenantId)->first();
        if (! $token) return;

        $vinculo = VinculoContatoTenant::where('contato_id', $contato->id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $vinculo || ! $vinculo->google_resource_name || ! $vinculo->google_etag) {
            return;
        }

        $novoEtag = app(GoogleService::class)->enriquecerContato(
            $token,
            $vinculo->google_resource_name,
            $vinculo->google_etag,
            $contato
        );

        if (! $novoEtag) {
            return;
        }

        $valoresEnviados = $vinculo->google_valores_enviados ?? [];
        foreach ($camposSincronizados as $campo) {
            $valoresEnviados[$campo] = (string) $contato->$campo;
        }

        $vinculo->update(['google_etag' => $novoEtag, 'google_valores_enviados' => $valoresEnviados]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ContatosControllerSyncHumanoTest`
Expected: PASS

- [ ] **Step 6: Atualizar `Internal/ContatoController::upsert()`**

Substituir o bloco (linhas ~38-49 hoje):

```php
        if (! $contato->wasRecentlyCreated && $nome) {
            if (! $contato->nome) {
                // Contato existia sem nome → atualiza master (seguro)
                $contato->update(['nome' => $nome]);
            } elseif (strtolower(trim($nome)) !== strtolower(trim($contato->nome))) {
                // pushName difere do master → fila de auditoria, master intacto
                $pendentes = $vinculo->campos_pendentes_auditoria ?? [];
                $pendentes['nome'] = ['sugerido' => $nome, 'origem' => 'whatsapp_pushname'];
                $vinculo->update(['campos_pendentes_auditoria' => $pendentes]);
            }
        }

        return response()->json([
            'contato_id'         => $contato->id,
            'opt_out'            => false,
            'novo'               => $contato->wasRecentlyCreated,
            'nome'               => $contato->nome,
            'auditoria_pendente' => (bool) ($vinculo->campos_pendentes_auditoria['nome'] ?? false),
        ]);
```

- [ ] **Step 7: Write the failing test — PushContatoParaGoogleJob grava linha de base, nunca marca humano**

```php
<?php

namespace Tests\Feature;

use App\Jobs\PushContatoParaGoogleJob;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushContatoParaGoogleJobLinhaBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_linha_de_base_mas_nao_marca_campo_editado_humano(): void
    {
        Http::fake(['people.googleapis.com/*' => Http::response(['resourceName' => 'people/c999'], 200)]);

        $tenant  = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['nome' => 'Marcos', 'empresa' => 'Faxa']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new PushContatoParaGoogleJob($contato->id, $tenant->id))->handle(app(\App\Services\GoogleService::class));

        $vinculo->refresh();
        $this->assertSame('Marcos', $vinculo->google_valores_enviados['nome'] ?? null);
        $this->assertSame('Faxa', $vinculo->google_valores_enviados['empresa'] ?? null);
        $this->assertNull($vinculo->campos_editados_humano);
    }
}
```

- [ ] **Step 8: Run test to verify it fails**

Run: `php artisan test --filter=PushContatoParaGoogleJobLinhaBaseTest`
Expected: FAIL — `google_valores_enviados` ainda vazio.

- [ ] **Step 9: Atualizar `PushContatoParaGoogleJob::handle()`**

Depois de `$vinculo->update(['google_resource_name' => $resourceName]);` (linha ~51), adicionar:

```php
        $valoresEnviados = [];
        foreach (['nome', 'sobrenome', 'empresa', 'email'] as $campo) {
            if (! empty($contato->$campo)) {
                $valoresEnviados[$campo] = (string) $contato->$campo;
            }
        }
        if ($valoresEnviados) {
            $vinculo->update(['google_valores_enviados' => $valoresEnviados]);
        }
```

- [ ] **Step 10: Run all four tests to verify they pass**

Run: `php artisan test --filter="ContatosControllerSyncHumanoTest|PushContatoParaGoogleJobLinhaBaseTest"`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/Painel/ContatosController.php app/Http/Controllers/Internal/ContatoController.php app/Jobs/PushContatoParaGoogleJob.php tests/Feature/ContatosControllerSyncHumanoTest.php tests/Feature/PushContatoParaGoogleJobLinhaBaseTest.php
git commit -m "feat(google-sync): push marca edição humana e atualiza linha de base"
```

---

### Task 5: Busca em tempo real no primeiro contato do lead

**Files:**
- Modify: `app/Services/GoogleService.php`
- Create: `app/Jobs/EnriquecerContatoNovoViaGoogleJob.php`
- Modify: `app/Models/VinculoContatoTenant.php`
- Test: `tests/Feature/GoogleServiceBuscarPorTelefoneTest.php`
- Test: `tests/Feature/EnriquecerContatoNovoViaGoogleJobTest.php`

**Interfaces:**
- Produces: `GoogleService::buscarContatoPorTelefone(GoogleToken $token, string $telefone): ?array` — retorna o array "pessoa" no mesmo formato usado por `ContatoSyncService::processarPessoa()` (`names`, `phoneNumbers`, `organizations`, `emailAddresses`), ou `null` se não achar ninguém. `EnriquecerContatoNovoViaGoogleJob` — job sem delay, fila `default`.
- Consumes: `ContatoSyncService::resolverCampoGoogle()` (Task 3), `ContatoSyncService::CAMPOS_SINCRONIZADOS`... na prática o job usa a mesma lista de 4 campos hardcoded, já que a constante é privada — ver Step 4.

- [ ] **Step 1: Write the failing test — GoogleService::buscarContatoPorTelefone**

```php
<?php

namespace Tests\Feature;

use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleServiceBuscarPorTelefoneTest extends TestCase
{
    use RefreshDatabase;

    private function tokenValido(): GoogleToken
    {
        $tenant = Tenant::factory()->create();

        return GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
    }

    public function test_busca_por_telefone_retorna_a_pessoa_encontrada(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [[
                'person' => [
                    'names'        => [['displayName' => 'Rodrigo Alves']],
                    'phoneNumbers' => [['value' => '5521999998888']],
                ],
            ]],
        ], 200)]);

        $resultado = app(GoogleService::class)->buscarContatoPorTelefone($this->tokenValido(), '5521999998888');

        $this->assertSame('Rodrigo Alves', $resultado['names'][0]['displayName']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'searchContacts')
            && str_contains($request->url(), 'readMask=names%2CphoneNumbers%2Corganizations%2CemailAddresses')
            && ! str_contains($request->url(), 'memberships'));
    }

    public function test_busca_sem_resultado_retorna_null(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response(['results' => []], 200)]);

        $resultado = app(GoogleService::class)->buscarContatoPorTelefone($this->tokenValido(), '5521900000000');

        $this->assertNull($resultado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GoogleServiceBuscarPorTelefoneTest`
Expected: FAIL — método não existe.

- [ ] **Step 3: Implementar `buscarContatoPorTelefone()` em `GoogleService`**

```php
    /**
     * Busca pontual por telefone via people:searchContacts — diferente de
     * listarContatos()/listarContatosDelta() (connections.list, lista tudo
     * via sync token). Usada só pelo EnriquecerContatoNovoViaGoogleJob, pra
     * checar um único telefone na hora que um lead novo chega. readMask
     * explícito, sem memberships — nunca usada pra classificar tipo_contato.
     */
    public function buscarContatoPorTelefone(GoogleToken $token, string $telefone): ?array
    {
        $token = $this->tokenValido($token);
        if (! $token) return null;

        try {
            $res = Http::withToken($token->access_token)
                ->get('https://people.googleapis.com/v1/people:searchContacts', [
                    'query'     => $telefone,
                    'readMask'  => 'names,phoneNumbers,organizations,emailAddresses',
                ]);

            if (! $res->successful()) {
                return null;
            }

            foreach ($res->json('results') ?? [] as $resultado) {
                $pessoa = $resultado['person'] ?? null;
                if (! $pessoa) continue;

                foreach ($pessoa['phoneNumbers'] ?? [] as $fone) {
                    if ($this->telefonesBatem($fone['value'] ?? '', $telefone)) {
                        return $pessoa;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('GoogleService buscarContatoPorTelefone exceção', ['erro' => $e->getMessage()]);
        }

        return null;
    }

    private function telefonesBatem(string $a, string $b): bool
    {
        $normalizar = fn (string $t) => preg_replace('/\D/', '', $t);
        $a = $normalizar($a);
        $b = $normalizar($b);
        return $a !== '' && (str_ends_with($a, $b) || str_ends_with($b, $a));
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=GoogleServiceBuscarPorTelefoneTest`
Expected: PASS

- [ ] **Step 5: Write the failing test — EnriquecerContatoNovoViaGoogleJob**

```php
<?php

namespace Tests\Feature;

use App\Jobs\EnriquecerContatoNovoViaGoogleJob;
use App\Models\Contato;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnriquecerContatoNovoViaGoogleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_nome_encontrado_no_google_pro_lead_novo(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response([
            'results' => [['person' => [
                'names'        => [['displayName' => 'Rodrigo Alves']],
                'phoneNumbers' => [['value' => '5521999998888']],
            ]]],
        ], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999998888', 'nome' => 'Sem Nome']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Rodrigo Alves', $contato->fresh()->nome);
    }

    public function test_sem_google_token_nao_faz_nada(): void
    {
        Http::fake();

        $tenant  = Tenant::factory()->create(); // sem GoogleToken
        $contato = Contato::factory()->create(['telefone' => '5521999997777', 'nome' => 'Sem Nome']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        Http::assertNothingSent();
        $this->assertSame('Sem Nome', $contato->fresh()->nome);
    }

    public function test_sem_resultado_no_google_nao_faz_nada(): void
    {
        Http::fake(['people.googleapis.com/v1/people:searchContacts*' => Http::response(['results' => []], 200)]);

        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => Carbon::now()->addHour(), 'scopes' => ['contacts'],
        ]);
        $contato = Contato::factory()->create(['telefone' => '5521999996666', 'nome' => 'Sem Nome']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        (new EnriquecerContatoNovoViaGoogleJob($vinculo->id))->handle(app(GoogleService::class), app(\App\Services\ContatoSyncService::class));

        $this->assertSame('Sem Nome', $contato->fresh()->nome);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `php artisan test --filter=EnriquecerContatoNovoViaGoogleJobTest`
Expected: FAIL — job não existe.

- [ ] **Step 7: Criar o job**

```php
<?php

namespace App\Jobs;

use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Design: docs/superpowers/specs/2026-08-26-sync-bidirecional-google-contatos-design.md
 * seção 10. Disparado sem delay quando um VinculoContatoTenant novo é criado
 * (ver VinculoContatoTenant::booted()) — pro lead inicial não esperar o
 * próximo ciclo do cron (até 15 min) pra mostrar o nome real, se já existir
 * salvo no Google do cliente. Roda em background (fila default), nunca
 * bloqueia a resposta do webhook/app/formulário que criou o contato.
 */
class EnriquecerContatoNovoViaGoogleJob implements ShouldQueue
{
    use Queueable;

    private const CAMPOS = ['nome', 'sobrenome', 'empresa', 'email'];

    public function __construct(private int $vinculoId) {}

    public function handle(GoogleService $google, ContatoSyncService $sync): void
    {
        $vinculo = VinculoContatoTenant::with('contato')->find($this->vinculoId);
        if (! $vinculo || ! $vinculo->contato) {
            return;
        }

        $token = GoogleToken::where('tenant_id', $vinculo->tenant_id)->first();
        if (! $token) {
            return;
        }

        $pessoa = $google->buscarContatoPorTelefone($token, $vinculo->contato->telefone);
        if (! $pessoa) {
            return;
        }

        $valores = [
            'nome'      => $pessoa['names'][0]['displayName'] ?? null,
            'sobrenome' => $pessoa['names'][0]['familyName'] ?? null,
            'empresa'   => $pessoa['organizations'][0]['name'] ?? null,
            'email'     => $pessoa['emailAddresses'][0]['value'] ?? null,
        ];

        foreach (self::CAMPOS as $campo) {
            $sync->resolverCampoGoogle($vinculo->contato, $vinculo, $campo, $valores[$campo]);
        }
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=EnriquecerContatoNovoViaGoogleJobTest`
Expected: PASS

- [ ] **Step 9: Cablear o hook em `VinculoContatoTenant::created()`**

Adicionar `booted()` ao model (não existe ainda):

```php
use App\Jobs\EnriquecerContatoNovoViaGoogleJob;

// ... dentro da classe, antes de contato()
protected static function booted(): void
{
    static::created(function (VinculoContatoTenant $vinculo) {
        EnriquecerContatoNovoViaGoogleJob::dispatch($vinculo->id);
    });
}
```

- [ ] **Step 10: Rodar a suíte inteira que toca `VinculoContatoTenant::create()` pra checar efeito colateral**

Run: `php artisan test --filter="ContatoSyncService|ContatosController|PushContatoParaGoogle|Internal"`
Expected: PASS — o dispatch acontece via `Bus::fake()` implícito? Não: sem `Queue::fake()`/`Bus::fake()` nos testes anteriores, o job real seria despachado de verdade (fila `database`, síncrona nula em teste) toda vez que um `VinculoContatoTenant::create()` rodar nesses testes — checar se algum teste anterior quebra por causa disso (ex: `Http::fake()` sem rota pra `searchContacts` cadastrada faz a chamada devolver uma resposta vazia/erro controlada, não deve lançar exceção). Se algum teste quebrar, adicionar `Bus::fake([EnriquecerContatoNovoViaGoogleJob::class])` no início dele.

- [ ] **Step 11: Commit**

```bash
git add app/Services/GoogleService.php app/Jobs/EnriquecerContatoNovoViaGoogleJob.php app/Models/VinculoContatoTenant.php tests/Feature/GoogleServiceBuscarPorTelefoneTest.php tests/Feature/EnriquecerContatoNovoViaGoogleJobTest.php
git commit -m "feat(google-sync): busca em tempo real no primeiro contato do lead"
```

---

### Task 6: Auditor — generalizar pra qualquer campo pendente

**Files:**
- Modify: `app/Http/Controllers/Painel/AuditorController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/auditor/index.blade.php`
- Test: `tests/Feature/AuditorControllerCampoTest.php`

**Interfaces:**
- Produces: `AuditorController::pendentesCampos()` (substitui `pendentes()`) retornando lista flat `{vinculo_id, campo, valor_atual, valor_sugerido, origem, telefone}` — uma linha por (vínculo × campo pendente). `AuditorController::aprovarCampo(Request, VinculoContatoTenant, string $campo)` e `rejeitarCampo(...)`.
- Rotas novas: `GET /auditor/pendentes` (mesmo path, resposta generalizada), `POST /auditor/pendente/{vinculo}/campo/{campo}/aprovar`, `POST /auditor/pendente/{vinculo}/campo/{campo}/rejeitar` — substituem `/aprovar` e `/rejeitar` sem `{campo}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditorControllerCampoTest extends TestCase
{
    use RefreshDatabase;

    private function vinculoComDoisPendentes(): VinculoContatoTenant
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create(['nome' => 'Marcia', 'empresa' => 'Transportes Silva']);

        return VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => [
                'nome'    => ['sugerido' => 'Marcia Souza', 'origem' => 'google'],
                'empresa' => ['sugerido' => 'Fretes ABC',  'origem' => 'google'],
            ],
        ]);
    }

    public function test_lista_pendentes_uma_linha_por_campo(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $res = $this->actingAs($user)->getJson('/api/painel/auditor/pendentes')->assertOk();

        $campos = collect($res->json('data'))->pluck('campo')->sort()->values();
        $this->assertSame(['empresa', 'nome'], $campos->all());
    }

    public function test_aprovar_um_campo_nao_afeta_o_outro_pendente(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/campo/nome/aprovar")
            ->assertOk();

        $vinculo->refresh();
        $this->assertSame('Marcia Souza', $vinculo->contato->fresh()->nome);
        $this->assertArrayNotHasKey('nome', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayHasKey('empresa', $vinculo->campos_pendentes_auditoria); // intacto
        $this->assertArrayHasKey('nome', $vinculo->campos_editados_humano ?? []); // aprovar = decisão humana
    }

    public function test_rejeitar_um_campo_mantem_valor_local_e_remove_a_pendencia(): void
    {
        $vinculo = $this->vinculoComDoisPendentes();
        $user    = User::factory()->create(['tenant_id' => $vinculo->tenant_id, 'perfil' => 'admin']);

        $this->actingAs($user)
            ->postJson("/api/painel/auditor/pendente/{$vinculo->id}/campo/empresa/rejeitar")
            ->assertOk();

        $vinculo->refresh();
        $this->assertSame('Transportes Silva', $vinculo->contato->fresh()->empresa);
        $this->assertArrayNotHasKey('empresa', $vinculo->campos_pendentes_auditoria);
        $this->assertArrayHasKey('nome', $vinculo->campos_pendentes_auditoria); // intacto
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuditorControllerCampoTest`
Expected: FAIL — rotas/métodos não existem.

- [ ] **Step 3: Substituir `pendentes()`, `aprovarNome()`, `rejeitarNome()` em `AuditorController`**

```php
    // ── Campos pendentes de auditoria (qualquer origem: google, humano_interno, whatsapp_pushname) ──

    public function pendentesCampos(Request $request): JsonResponse
    {
        $vinculos = VinculoContatoTenant::with('contato')
            ->whereNotNull('campos_pendentes_auditoria')
            ->orderBy('contato_id')
            ->get();

        $itens = [];
        foreach ($vinculos as $v) {
            foreach ($v->campos_pendentes_auditoria ?? [] as $campo => $pendencia) {
                $itens[] = [
                    'vinculo_id'     => $v->id,
                    'contato_id'     => $v->contato_id,
                    'tenant_id'      => $v->tenant_id,
                    'campo'          => $campo,
                    'valor_atual'    => $v->contato?->$campo,
                    'valor_sugerido' => $pendencia['sugerido'] ?? null,
                    'origem'         => $pendencia['origem'] ?? null,
                    'telefone'       => $this->mascarar($v->contato?->telefone ?? '', 'telefone'),
                ];
            }
        }

        return response()->json(['data' => $itens, 'total' => count($itens)]);
    }

    public function aprovarCampo(Request $request, VinculoContatoTenant $vinculo, string $campo): JsonResponse
    {
        $pendencia = $vinculo->campos_pendentes_auditoria[$campo] ?? null;
        if (! $pendencia) {
            return response()->json(['erro' => 'Nenhuma sugestão pendente pra este campo.'], 422);
        }

        $valorAntigo = $vinculo->contato?->$campo;
        $valorNovo   = $pendencia['sugerido'];

        $vinculo->contato?->update([$campo => $valorNovo]);

        $pendentes = $vinculo->campos_pendentes_auditoria;
        unset($pendentes[$campo]);

        $humano = $vinculo->campos_editados_humano ?? [];
        $humano[$campo] = now()->toIso8601String(); // aprovar é uma decisão humana

        $vinculo->update([
            'campos_pendentes_auditoria' => $pendentes ?: null,
            'campos_editados_humano'     => $humano,
        ]);

        AuditLog::registrar(
            tabela:      'contatos',
            registroId:  $vinculo->contato_id,
            acao:        'aprovar_campo',
            campo:       $campo,
            valorAntigo: $valorAntigo,
            valorNovo:   $valorNovo,
            contexto:    ['vinculo_id' => $vinculo->id, 'tenant_id' => $vinculo->tenant_id, 'origem' => $pendencia['origem'] ?? null]
        );

        return response()->json(['ok' => true]);
    }

    public function rejeitarCampo(VinculoContatoTenant $vinculo, string $campo): JsonResponse
    {
        $pendencia = $vinculo->campos_pendentes_auditoria[$campo] ?? null;
        if (! $pendencia) {
            return response()->json(['erro' => 'Nenhuma sugestão pendente pra este campo.'], 422);
        }

        $pendentes = $vinculo->campos_pendentes_auditoria;
        unset($pendentes[$campo]);
        $vinculo->update(['campos_pendentes_auditoria' => $pendentes ?: null]);

        AuditLog::registrar(
            tabela:      'vinculos_contato_tenant',
            registroId:  $vinculo->id,
            acao:        'rejeitar_campo',
            campo:       $campo,
            valorAntigo: $pendencia['sugerido'] ?? null,
            valorNovo:   null,
            contexto:    ['contato_id' => $vinculo->contato_id, 'tenant_id' => $vinculo->tenant_id]
        );

        return response()->json(['ok' => true]);
    }
```

`stats()` (linha ~28) troca `VinculoContatoTenant::where('auditoria_pendente', true)->count()` por:

```php
        $pendentes = VinculoContatoTenant::whereNotNull('campos_pendentes_auditoria')->get()
            ->sum(fn ($v) => count($v->campos_pendentes_auditoria ?? []));
```

- [ ] **Step 4: Atualizar rotas em `routes/web.php`** (linhas ~314-316)

```php
        Route::get('/auditor/pendentes',                                     [AuditorController::class, 'pendentesCampos']);
        Route::post('/auditor/pendente/{vinculo}/campo/{campo}/aprovar',     [AuditorController::class, 'aprovarCampo']);
        Route::post('/auditor/pendente/{vinculo}/campo/{campo}/rejeitar',    [AuditorController::class, 'rejeitarCampo']);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AuditorControllerCampoTest`
Expected: PASS

- [ ] **Step 6: Generalizar a tabela da aba "Pendentes" em `resources/views/auditor/index.blade.php`**

Trocar as colunas fixas "Nome atual"/"Nome sugerido" (linhas ~105-126) por uma coluna "Campo" + "Valor atual"/"Valor sugerido" genéricos, e os métodos JS `aprovarNome`/`rejeitarNome` (linhas ~493-507) por:

```javascript
async aprovarCampo(item) {
    const res = await this.api(`/api/painel/auditor/pendente/${item.vinculo_id}/campo/${item.campo}/aprovar`, 'POST');
    if (res.ok) {
        this.pendentes = this.pendentes.filter(p => !(p.vinculo_id === item.vinculo_id && p.campo === item.campo));
        this.carregarStats();
    }
},
async rejeitarCampo(item) {
    if (! confirm(`Rejeitar sugestão "${item.valor_sugerido}" pro campo "${item.campo}" e manter "${item.valor_atual}"?`)) return;
    const res = await this.api(`/api/painel/auditor/pendente/${item.vinculo_id}/campo/${item.campo}/rejeitar`, 'POST');
    if (res.ok) {
        this.pendentes = this.pendentes.filter(p => !(p.vinculo_id === item.vinculo_id && p.campo === item.campo));
        this.carregarStats();
    }
},
```

E o `:key` do `x-for` (linha ~105) passa a ser `` `${item.vinculo_id}-${item.campo}` `` (chave composta, já que agora pode haver mais de uma linha por `vinculo_id`).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Painel/AuditorController.php routes/web.php resources/views/auditor/index.blade.php tests/Feature/AuditorControllerCampoTest.php
git commit -m "feat(google-sync): Auditor generalizado pra qualquer campo pendente, não só nome"
```

---

### Task 7: `KanbanController` — indicador de pendência generalizado

**Files:**
- Modify: `app/Http/Controllers/Painel/KanbanController.php` (métodos `index`, linhas ~119-132, e `show`, linhas ~168-181)
- Test: `tests/Feature/KanbanControllerPendenciaGeneralizadaTest.php`

**Interfaces:**
- Consumes: `campos_pendentes_auditoria` (Task 1/6). Mantém as MESMAS propriedades no JSON de saída (`nome_local`, `auditoria_pendente`) pro frontend Blade/Alpine do Kanban não precisar mudar nesta task — só a origem do dado muda.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\User;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanControllerPendenciaGeneralizadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_do_ticket_reflete_pendencia_de_nome_vinda_da_estrutura_nova(): void
    {
        $tenant  = Tenant::factory()->create();
        $user    = User::factory()->create(['tenant_id' => $tenant->id, 'perfil' => 'admin']);
        $contato = Contato::factory()->create(['nome' => 'Marcia']);
        VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'campos_pendentes_auditoria' => ['nome' => ['sugerido' => 'Marcia Souza', 'origem' => 'google']],
        ]);
        $ticket = TicketAtendimento::create([
            'tenant_id' => $tenant->id, 'contato_id' => $contato->id,
            'coluna_kanban' => 'lead_novo', 'agente_responsavel' => 'bot',
        ]);

        $res = $this->actingAs($user)->getJson("/api/painel/kanban/ticket/{$ticket->id}")->assertOk();

        $this->assertSame('Marcia Souza', $res->json('contato.nome_local'));
        $this->assertTrue($res->json('contato.auditoria_pendente'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=KanbanControllerPendenciaGeneralizadaTest`
Expected: FAIL — `nome_local`/`auditoria_pendente` continuam lendo `nome_sugerido` (sempre null agora).

- [ ] **Step 3: Atualizar `KanbanController::index()`** (linhas ~126-132)

```php
        $todosTickets->each(function ($ticket) use ($vinculos) {
            if ($ticket->contato && $vinculos->has($ticket->contato_id)) {
                $v = $vinculos[$ticket->contato_id];
                $pendenteNome = $v->campos_pendentes_auditoria['nome'] ?? null;
                $ticket->contato->nome_local        = $pendenteNome['sugerido'] ?? null;
                $ticket->contato->auditoria_pendente = (bool) $pendenteNome;
            }
        });
```

- [ ] **Step 4: Atualizar `KanbanController::show()`** (linhas ~177-180)

```php
            if ($vinculo) {
                $pendenteNome = $vinculo->campos_pendentes_auditoria['nome'] ?? null;
                $model->contato->nome_local        = $pendenteNome['sugerido'] ?? null;
                $model->contato->auditoria_pendente = (bool) $pendenteNome;
            }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=KanbanControllerPendenciaGeneralizadaTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Painel/KanbanController.php tests/Feature/KanbanControllerPendenciaGeneralizadaTest.php
git commit -m "feat(google-sync): Kanban lê pendência de nome da estrutura generalizada"
```

---

### Task 8: Cron de 15 minutos

**Files:**
- Modify: `routes/console.php`
- Modify: `app/Http/Controllers/Painel/DashboardController.php` (label do log, linha ~97)

**Interfaces:** nenhuma nova — só configuração.

- [ ] **Step 1: Alterar o schedule**

Em `routes/console.php`:

```php
// Sincroniza contatos do Google para todos os tenants a cada 15 minutos
// Delta sync: só busca novos/alterados desde o último sync via SyncToken —
// intervalo reduzido de 6h pra 15min (pedido do Leonardo, 2026-08-26: "time
// é fundamental" pro lead inicial; o delta sync é barato o suficiente pra
// rodar bem mais frequente sem pesar na cota da API do Google).
Schedule::command('contatos:sincronizar-google')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/google-sync.log'));
```

- [ ] **Step 2: Atualizar o label mostrado no painel de automações**

Em `app/Http/Controllers/Painel/DashboardController.php`, linha ~97:

```php
            ['nome' => 'Sincronizar Google Contacts', 'log' => 'google-sync.log',        'horario' => 'a cada 15min'],
```

- [ ] **Step 3: Não há teste automatizado pra frequência do cron** (Laravel Scheduler não roda em `php artisan test`) — confirmar manualmente rodando:

Run: `php artisan schedule:list | grep sincronizar-google`
Expected: mostra `*/15 * * * *` (ou equivalente) em vez de `0 */6 * * *`.

- [ ] **Step 4: Commit**

```bash
git add routes/console.php app/Http/Controllers/Painel/DashboardController.php
git commit -m "feat(google-sync): cron de 6h pra 15min"
```

---

### Task 9: Dropar colunas legadas

**Files:**
- Create: `database/migrations/2026_08_26_000004_drop_campos_legados_google_sync_vinculos_contato_tenant_table.php`
- Modify: `app/Models/VinculoContatoTenant.php`

**Interfaces:** nenhuma nova — remoção final, só depois de confirmar que nada mais lê `google_given_name`/`nome_sugerido`/`auditoria_pendente`.

**Pré-condição pra esta task:** rodar antes de tudo:

```bash
grep -rn "nome_sugerido\|auditoria_pendente\|google_given_name" app/ resources/views/ --include="*.php" --include="*.blade.php"
```

Deve retornar SÓ este arquivo de migration e o `dadosVinculo()` em `ContatoSyncService` (que ainda escreve `google_given_name` — Task 3 não mexeu nisso porque a coluna continuava existindo; remover a chave `google_given_name` do array retornado por `dadosVinculo()` faz parte desta task, já que a coluna deixa de existir). Se aparecer qualquer outro arquivo, uma task anterior ficou incompleta — não prosseguir sem resolver.

- [ ] **Step 1: Remover `google_given_name` de `ContatoSyncService::dadosVinculo()`**

```php
    private function dadosVinculo(array $pessoa): array
    {
        return [
            'google_resource_name' => $pessoa['resourceName'] ?? null,
            'google_etag'          => $pessoa['etag'] ?? null,
        ];
    }
```

- [ ] **Step 2: Rodar a suíte de `ContatoSyncService` pra confirmar que nada dependia do campo removido**

Run: `php artisan test --filter=ContatoSyncService`
Expected: PASS

- [ ] **Step 3: Criar a migration de drop**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última task do plano de sync bidirecional Google Contatos — roda só
 * depois de todo call site migrado pra campos_pendentes_auditoria/
 * campos_editados_humano/google_valores_enviados (Tasks 1-8). Ver checagem
 * de pré-condição na Task 9 do plano
 * (docs/superpowers/plans/2026-08-26-sync-bidirecional-google-contatos.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->dropColumn(['google_given_name', 'nome_sugerido', 'auditoria_pendente']);
        });
    }

    public function down(): void
    {
        Schema::table('vinculos_contato_tenant', function (Blueprint $table) {
            $table->string('google_given_name', 200)->nullable();
            $table->string('nome_sugerido', 200)->nullable();
            $table->boolean('auditoria_pendente')->default(false);
        });
    }
};
```

- [ ] **Step 4: Remover as três colunas de `$fillable`/`$casts` em `VinculoContatoTenant`**

```php
    protected $casts = [
        'created_at'                 => 'datetime',
        'bloqueado_em'                => 'datetime',
        'google_valores_enviados'    => 'array',
        'campos_editados_humano'     => 'array',
        'campos_pendentes_auditoria' => 'array',
    ];

    protected $fillable = [
        'contato_id',
        'tenant_id',
        'google_resource_name',
        'google_etag',
        'bloqueado_em',
        'google_valores_enviados',
        'campos_editados_humano',
        'campos_pendentes_auditoria',
    ];
```

- [ ] **Step 5: Rodar a suíte completa**

Run: `php artisan test`
Expected: PASS (exceto a falha pré-existente e não relacionada de `ExampleTest`).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_26_000004_drop_campos_legados_google_sync_vinculos_contato_tenant_table.php app/Models/VinculoContatoTenant.php app/Services/ContatoSyncService.php
git commit -m "feat(google-sync): remove colunas legadas google_given_name/nome_sugerido/auditoria_pendente"
```

---

## Depois de todas as tasks

Seguir o fluxo padrão de deploy do projeto (`CLAUDE.md`): push da branch, clone de teste isolado na VPS com `.env`/`vendor`/`node_modules` copiados, `php artisan test` completo, e só então merge `--no-ff` pra `main` + `bash deploy.sh`. Não fazer deploy manual nem editar a VPS direto.
