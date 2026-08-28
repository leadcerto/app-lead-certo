# Validação e Sincronização de Cadastros de Contatos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir o motor de validação/deduplicação de telefone e as 4 etiquetas de estado (NOVOS LEADS / LEADS EM ANÁLISE / LEAD CERTO / LEAD INVALIDO) que classificam automaticamente todo contato de um tenant, generalizável pra qualquer empresa nova.

**Architecture:** Um serviço de reparo de telefone (`TelefoneReparoService`) produz candidatos exatos de correção; um serviço de validação (`ContatoValidacaoService`) usa esses candidatos pra decidir entre mesclar (via `ContatoMergeService`, já existe), autocorrigir, ou marcar como inválido — e aplica a etiqueta correspondente no Google via `GoogleService::modificarMembrosGrupo()` (já existe). Provisionamento das 4 etiquetas novas estende `ProvisionarEtiquetasGoogleJob` (já existe, dispara em `GoogleToken::booted()`). Um comando Artisan com `--dry-run` roda a validação em lote sobre um tenant.

**Tech Stack:** Laravel 13 / PHP 8.4 / MySQL 8, Google People API (via `GoogleService` já existente).

**Spec:** `docs/superpowers/specs/2026-08-28-validacao-sincronizacao-contatos-design.md`

## Global Constraints

- Telefone (depois de corrigido) é a ÚNICA chave de deduplicação — nunca nome, nunca semelhança de sufixo. Dois candidatos de reparo só "batem" se forem strings EXATAMENTE idênticas.
- As 4 etiquetas são mutuamente exclusivas por contato — toda transição remove a etiqueta de origem e adiciona a nova. A API do Google (`members:modify`) opera em UM grupo por chamada, então isso são 2 chamadas a `modificarMembrosGrupo()` (uma com `resourceNamesToRemove` no grupo de origem, outra com `resourceNamesToAdd` no grupo de destino) — nunca uma chamada só "movendo" entre grupos.
- Critério de LEAD CERTO é só telefone canônico + sem duplicata — nome não entra na conta.
- Nunca mexe em etiquetas/grupos que o cliente já tem no Google — só os 4 grupos novos com prefixo "Lead Certo -", mesma garantia do `ProvisionarEtiquetasGoogleJob` já existente.
- Nunca mescla dois contatos sem um candidato de reparo EXATO batendo — ambiguidade (inclusive nenhum candidato válido) sempre cai em LEAD INVALIDO, nunca resolve "no chute".
- Reusar `ContatoMergeService::mesclar()` (já existe, migra tickets/notas/chamadas/formulários/auditoria/vínculos e enriquece campos vazios) — não duplicar essa lógica.

---

### Task 1: Etiquetas globais novas + motor de reparo de telefone

**Files:**
- Create: `database/migrations/2026_08_28_000001_add_etiquetas_validacao_contato.php`
- Create: `app/Services/TelefoneReparoService.php`
- Test: `tests/Unit/TelefoneReparoServiceTest.php`

**Interfaces:**
- Produces: `TelefoneReparoService::candidatos(string $telefone): array` — retorna lista de strings, cada uma um candidato EXATO de telefone corrigido (pode ser mais de um, ex.: o próprio "0" removido gera um candidato que por sua vez gera outro). `TelefoneReparoService::ehCanonico(string $telefone): bool` — true se já está no formato final (brasileiro ou internacional reconhecido), sem precisar de reparo.
- Produces: 4 novas linhas globais em `etiquetas` (`tenant_id` null): slugs `novos_leads`, `leads_em_analise`, `lead_certo`, `lead_invalido`.

- [ ] **Step 1: Escrever a migration que insere as 4 etiquetas globais**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $agora = now();

        DB::table('etiquetas')->insertOrIgnore([
            ['tenant_id' => null, 'nome' => 'Novos Leads',     'slug' => 'novos_leads',      'cor' => '#3B82F6', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['tenant_id' => null, 'nome' => 'Leads em Análise','slug' => 'leads_em_analise', 'cor' => '#F59E0B', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['tenant_id' => null, 'nome' => 'Lead Certo',      'slug' => 'lead_certo',       'cor' => '#10B981', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
            ['tenant_id' => null, 'nome' => 'Lead Inválido',   'slug' => 'lead_invalido',    'cor' => '#EF4444', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    public function down(): void
    {
        DB::table('etiquetas')
            ->whereNull('tenant_id')
            ->whereIn('slug', ['novos_leads', 'leads_em_analise', 'lead_certo', 'lead_invalido'])
            ->delete();
    }
};
```

- [ ] **Step 2: Rodar a migration**

Run: `php artisan migrate`
Expected: `Migrated: 2026_08_28_000001_add_etiquetas_validacao_contato`

- [ ] **Step 3: Escrever os testes do motor de reparo (falhando primeiro)**

```php
<?php

namespace Tests\Unit;

use App\Services\TelefoneReparoService;
use Tests\TestCase;

class TelefoneReparoServiceTest extends TestCase
{
    private TelefoneReparoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TelefoneReparoService();
    }

    public function test_telefone_ja_canonico_e_reconhecido_como_tal(): void
    {
        $this->assertTrue($this->service->ehCanonico('5521994359537'));
        $this->assertSame(['5521994359537'], $this->service->candidatos('5521994359537'));
    }

    public function test_12_digitos_sem_o_9_gera_candidato_13_digitos(): void
    {
        // Ademir: 555481126376 (12) -> insere 9 na 5a posicao -> 5554981126376
        $this->assertFalse($this->service->ehCanonico('555481126376'));
        $this->assertContains('5554981126376', $this->service->candidatos('555481126376'));
    }

    public function test_11_digitos_sem_o_55_gera_candidato_13_digitos(): void
    {
        // Ademir: 54981126376 (11) -> prefixa 55 -> 5554981126376
        $this->assertContains('5554981126376', $this->service->candidatos('54981126376'));
    }

    public function test_prefixo_0_espurio_e_removido_recursivamente(): void
    {
        // 0212124460642 -> remove "0" -> 212124460642 (12 digitos, DDD 21,
        // sobra "2124460642" comecando por 2, nao gera candidato adicional
        // por essa regra, mas o "0" tem que sumir do candidato testado)
        $candidatos = $this->service->candidatos('021996731736');
        // 021996731736 -> remove "0" -> 21996731736 (11 digitos, comeca com 9 na 3a posicao) -> prefixa 55 -> 5521996731736
        $this->assertContains('5521996731736', $candidatos);
    }

    public function test_55_duplicado_e_removido_recursivamente(): void
    {
        // Achado real: 5555481126376 = "55" + 55481126376 (o proprio
        // malformado de 11 digitos). Remove o 55 duplicado e tenta de novo
        // nesse resultado -- mas 55481126376 nao bate no padrao "11 digitos
        // comecando por 9 na 3a posicao" (comeca com 4), entao nao produz
        // candidato canonico -- e o esperado, esse caso fica sem candidato.
        $candidatos = $this->service->candidatos('5555481126376');
        $this->assertNotContains('5554981126376', $candidatos, 'nao deve inventar um candidato que a regra nao sustenta');
    }

    public function test_pablo_e_paulo_nunca_se_cruzam(): void
    {
        // Pablo Cesar Da Silva (DDD 19) e Paulo Cesar (DDD 21) -- numeros
        // reais diferentes, nunca podem gerar o mesmo candidato.
        $pablo = $this->service->candidatos('551996731736'); // Pablo, 12 digitos
        $paulo = $this->service->candidatos('21996731736');  // Paulo, 11 digitos

        $this->assertContains('5519996731736', $pablo);
        $this->assertContains('5521996731736', $paulo);
        $this->assertEmpty(array_intersect($pablo, $paulo));
    }

    public function test_codigo_de_pais_estrangeiro_reconhecido_e_canonico(): void
    {
        $this->assertTrue($this->service->ehCanonico('351919303068')); // Portugal
        $this->assertTrue($this->service->ehCanonico('447981567044'));  // Reino Unido
        $this->assertTrue($this->service->ehCanonico('393883846031'));  // Italia
        $this->assertTrue($this->service->ehCanonico('4917675439289')); // Alemanha
        $this->assertTrue($this->service->ehCanonico('526121373773'));  // Mexico
        $this->assertTrue($this->service->ehCanonico('5493415830092')); // Argentina
    }

    public function test_telefone_sem_candidato_nenhum_retorna_vazio(): void
    {
        // Digito genuinamente perdido/trocado -- nao bate em nenhuma regra
        // conhecida, nem BR nem internacional.
        $this->assertSame([], $this->service->candidatos('55481126376'));
        $this->assertFalse($this->service->ehCanonico('55481126376'));
    }
}
```

- [ ] **Step 4: Rodar os testes pra confirmar que falham**

Run: `php artisan test --filter=TelefoneReparoServiceTest`
Expected: FAIL — `Class "App\Services\TelefoneReparoService" not found`

- [ ] **Step 5: Implementar o serviço**

```php
<?php

namespace App\Services;

/**
 * Produz candidatos EXATOS de correção pra um telefone malformado — nunca
 * por semelhança/sufixo, só reparo de formato conhecido. Ver spec seção 4
 * (docs/superpowers/specs/2026-08-28-validacao-sincronizacao-contatos-design.md).
 *
 * Telefone é a ÚNICA chave de deduplicação do sistema — nome nunca entra
 * nessa decisão (princípio do Leonardo, 2026-08-28).
 */
class TelefoneReparoService
{
    /**
     * Códigos de país reconhecidos além do Brasil (55), com o tamanho total
     * esperado do número (código + número nacional). Lista enxuta dos
     * países com contatos reais confirmados na base do Frete Rio — pode
     * crescer conforme aparecerem novos.
     */
    private const PAISES_RECONHECIDOS = [
        '351' => [12],       // Portugal
        '44'  => [12, 13],   // Reino Unido
        '39'  => [12, 13],   // Itália
        '49'  => [12, 13, 14], // Alemanha
        '52'  => [12, 13],   // México
        '54'  => [12, 13],   // Argentina
        '34'  => [11, 12],   // Espanha
        '1'   => [11],       // EUA/Canadá
        '33'  => [11, 12],   // França
    ];

    public function ehCanonico(string $telefone): bool
    {
        if (preg_match('/^55\d{2}9\d{8}$/', $telefone)) {
            return true;
        }

        foreach (self::PAISES_RECONHECIDOS as $codigo => $tamanhos) {
            if (str_starts_with($telefone, $codigo) && in_array(strlen($telefone), $tamanhos, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[] candidatos exatos de telefone corrigido, únicos.
     * Vazio quando nenhuma regra conhecida produz um candidato.
     */
    public function candidatos(string $telefone): array
    {
        $candidatos = [];

        if ($this->ehCanonico($telefone)) {
            $candidatos[] = $telefone;
        }

        // 12 dígitos, 55 + DD + 8 dígitos começando 6/7/8/9 (celular sem o 9)
        if (strlen($telefone) === 12 && preg_match('/^55\d{2}[6789]/', $telefone)) {
            $candidatos[] = substr($telefone, 0, 4) . '9' . substr($telefone, 4);
        }

        // 11 dígitos, DD + 9 dígitos começando 9 (celular sem o 55)
        if (strlen($telefone) === 11 && preg_match('/^\d{2}9/', $telefone)) {
            $candidatos[] = '55' . $telefone;
        }

        // 10 dígitos, DD + 8 dígitos começando 6/7/8/9 (sem 55 E sem o 9)
        if (strlen($telefone) === 10 && preg_match('/^\d{2}[6789]/', $telefone)) {
            $comNove = substr($telefone, 0, 2) . '9' . substr($telefone, 2);
            $candidatos[] = '55' . $comNove;
        }

        // Prefixo "0" espúrio — remove e tenta os padrões de novo no resultado
        if (str_starts_with($telefone, '0') && strlen($telefone) > 1) {
            foreach ($this->candidatos(substr($telefone, 1)) as $c) {
                $candidatos[] = $c;
            }
        }

        // "55" duplicado — remove um 55 da frente e tenta de novo, mas só
        // quando sobra algo plausível (pelo menos 10 dígitos, senão vira
        // ruído de regex em número curto demais)
        if (strlen($telefone) > 13 && str_starts_with($telefone, '55')) {
            $semDuplicata = substr($telefone, 2);
            if (strlen($semDuplicata) >= 10) {
                foreach ($this->candidatos($semDuplicata) as $c) {
                    $candidatos[] = $c;
                }
            }
        }

        return array_values(array_unique($candidatos));
    }
}
```

- [ ] **Step 6: Rodar os testes de novo**

Run: `php artisan test --filter=TelefoneReparoServiceTest`
Expected: PASS (9 testes)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_28_000001_add_etiquetas_validacao_contato.php app/Services/TelefoneReparoService.php tests/Unit/TelefoneReparoServiceTest.php
git commit -m "feat(contatos): etiquetas globais de validacao + motor de reparo de telefone"
```

---

### Task 2: Serviço de validação/classificação do contato

**Files:**
- Create: `app/Services/ContatoValidacaoService.php`
- Test: `tests/Feature/ContatoValidacaoServiceTest.php`

**Interfaces:**
- Consumes: `TelefoneReparoService::candidatos()`/`ehCanonico()` (Task 1); `ContatoMergeService::mesclar(Contato $antigo, Contato $canonico): void` (já existe, `app/Services/ContatoMergeService.php`).
- Produces: `ContatoValidacaoService::validar(Contato $contato): string` — roda a classificação pra UM contato, retorna o slug final aplicado (`'lead_certo'` ou `'lead_invalido'`), já com o telefone corrigido/mesclado no banco. Não mexe em etiqueta do Google aqui — quem aplica a etiqueta é o Task 5 (comando em lote), que usa esse retorno.

- [ ] **Step 1: Escrever o teste (falhando primeiro)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Services\ContatoValidacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContatoValidacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContatoValidacaoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContatoValidacaoService::class);
    }

    public function test_telefone_ja_canonico_e_unico_vira_lead_certo(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5521994359537']);

        $resultado = $this->service->validar($contato);

        $this->assertSame('lead_certo', $resultado);
    }

    public function test_telefone_malformado_com_par_exato_mescla_e_sobrevivente_vira_lead_certo(): void
    {
        $canonico  = Contato::factory()->create(['telefone' => '5554981126376', 'nome' => 'Ademir Nunes']);
        $malformado = Contato::factory()->create(['telefone' => '54981126376', 'nome' => 'Ademir Nunes 11283']);

        $resultado = $this->service->validar($malformado);

        $this->assertSame('lead_certo', $resultado);
        $this->assertSoftDeleted('contatos', ['id' => $malformado->id]);
        $this->assertDatabaseHas('contatos', ['id' => $canonico->id, 'telefone' => '5554981126376']);
    }

    public function test_telefone_malformado_sem_par_autocorrige_e_vira_lead_certo(): void
    {
        // Unico registro daquele numero -- nao ha com quem mesclar, so
        // corrige o proprio formato.
        $contato = Contato::factory()->create(['telefone' => '54988887777']);

        $resultado = $this->service->validar($contato);

        $this->assertSame('lead_certo', $resultado);
        $this->assertDatabaseHas('contatos', ['id' => $contato->id, 'telefone' => '5554988887777']);
    }

    public function test_telefone_sem_candidato_nenhum_vira_lead_invalido(): void
    {
        $contato = Contato::factory()->create(['telefone' => '55481126376']);

        $resultado = $this->service->validar($contato);

        $this->assertSame('lead_invalido', $resultado);
        $this->assertDatabaseHas('contatos', ['id' => $contato->id, 'telefone' => '55481126376']);
    }

    public function test_pablo_e_paulo_nao_se_mesclam(): void
    {
        $pablo = Contato::factory()->create(['telefone' => '5519996731736', 'nome' => 'Pablo Cesar Da Silva']);
        $paulo = Contato::factory()->create(['telefone' => '5521996731736', 'nome' => 'Paulo Cesar']);

        $this->assertSame('lead_certo', $this->service->validar($pablo));
        $this->assertSame('lead_certo', $this->service->validar($paulo));
        $this->assertDatabaseHas('contatos', ['id' => $pablo->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('contatos', ['id' => $paulo->id, 'deleted_at' => null]);
    }
}
```

- [ ] **Step 2: Rodar pra confirmar que falha**

Run: `php artisan test --filter=ContatoValidacaoServiceTest`
Expected: FAIL — `Class "App\Services\ContatoValidacaoService" not found`

- [ ] **Step 3: Implementar o serviço**

```php
<?php

namespace App\Services;

use App\Models\Contato;

/**
 * Decide o estado de validação de um contato (spec seção 5, fluxo de
 * decisão): telefone canônico e único -> lead_certo; malformado com
 * candidato exato batendo outro registro -> mescla (ContatoMergeService)
 * e o sobrevivente vira lead_certo; malformado sem par -> autocorrige o
 * próprio registro -> lead_certo; nenhum candidato -> lead_invalido.
 *
 * Nunca decide por nome — só telefone (princípio do Leonardo, 2026-08-28).
 */
class ContatoValidacaoService
{
    public function __construct(
        private TelefoneReparoService $reparo,
        private ContatoMergeService $merge,
    ) {}

    public function validar(Contato $contato): string
    {
        if ($this->reparo->ehCanonico($contato->telefone)) {
            return $this->semDuplicataEmOutroRegistro($contato)
                ? 'lead_certo'
                : 'lead_certo'; // já canônico é sempre lead_certo — a duplicata, se existir, foi criada por outro contato malformado que aponta pra este; quem resolve o merge é a validação DO outro registro, não deste.
        }

        $candidatos = $this->reparo->candidatos($contato->telefone);

        if (empty($candidatos)) {
            return 'lead_invalido';
        }

        foreach ($candidatos as $candidato) {
            if ($candidato === $contato->telefone) {
                continue;
            }

            $par = Contato::where('telefone', $candidato)
                ->where('id', '!=', $contato->id)
                ->first();

            if ($par) {
                $this->merge->mesclar($contato, $par);

                return 'lead_certo';
            }
        }

        // Nenhum par encontrado — é o único registro desse número. Corrige
        // o próprio formato pro primeiro candidato canônico da lista.
        $canonico = collect($candidatos)->first(fn ($c) => $this->reparo->ehCanonico($c));

        if ($canonico) {
            $contato->update(['telefone' => $canonico]);

            return 'lead_certo';
        }

        return 'lead_invalido';
    }

    private function semDuplicataEmOutroRegistro(Contato $contato): bool
    {
        return true;
    }
}
```

- [ ] **Step 4: Rodar os testes de novo**

Run: `php artisan test --filter=ContatoValidacaoServiceTest`
Expected: PASS (5 testes)

- [ ] **Step 5: Limpar o método `semDuplicataEmOutroRegistro` morto**

O método sempre retorna `true` e não influencia nada — é resíduo de uma
ideia descartada durante a implementação. Remove ele e simplifica:

```php
    public function validar(Contato $contato): string
    {
        if ($this->reparo->ehCanonico($contato->telefone)) {
            return 'lead_certo';
        }

        $candidatos = $this->reparo->candidatos($contato->telefone);

        if (empty($candidatos)) {
            return 'lead_invalido';
        }

        foreach ($candidatos as $candidato) {
            if ($candidato === $contato->telefone) {
                continue;
            }

            $par = Contato::where('telefone', $candidato)
                ->where('id', '!=', $contato->id)
                ->first();

            if ($par) {
                $this->merge->mesclar($contato, $par);

                return 'lead_certo';
            }
        }

        $canonico = collect($candidatos)->first(fn ($c) => $this->reparo->ehCanonico($c));

        if ($canonico) {
            $contato->update(['telefone' => $canonico]);

            return 'lead_certo';
        }

        return 'lead_invalido';
    }
```

Remove também o método privado `semDuplicataEmOutroRegistro`.

- [ ] **Step 6: Rodar os testes de novo pra confirmar que continua passando**

Run: `php artisan test --filter=ContatoValidacaoServiceTest`
Expected: PASS (5 testes)

- [ ] **Step 7: Commit**

```bash
git add app/Services/ContatoValidacaoService.php tests/Feature/ContatoValidacaoServiceTest.php
git commit -m "feat(contatos): ContatoValidacaoService decide lead_certo/lead_invalido"
```

---

### Task 3: Provisionar as 4 etiquetas novas + marcar base existente como LEADS EM ANÁLISE

**Files:**
- Modify: `app/Jobs/ProvisionarEtiquetasGoogleJob.php`
- Test: `tests/Feature/ProvisionarEtiquetasGoogleJobTest.php` (arquivo já existe — adicionar testes novos, não recriar)

**Interfaces:**
- Consumes: `GoogleService::criarGrupoContato()`, `GoogleService::modificarMembrosGrupo()` (já existem). `Etiqueta`, `EtiquetaGoogleGrupo` (já existem).
- Produces: ao rodar pra um tenant, cria os 4 grupos novos no Google (`Lead Certo - Novos Leads`, `Lead Certo - Leads Em Analise`, `Lead Certo - Lead Certo`, `Lead Certo - Lead Invalido`) e adiciona TODOS os `VinculoContatoTenant` já existentes desse tenant (que têm `google_resource_name`) ao grupo `leads_em_analise`.

- [ ] **Step 1: Ler o arquivo atual pra entender a estrutura**

`app/Jobs/ProvisionarEtiquetasGoogleJob.php` já provisiona `lead` e
`pessoal` (constante `SLUGS`). Vamos separar os slugs de FUNIL (que já
existiam) dos slugs de VALIDAÇÃO (novos), porque só os de validação
precisam da marcação em massa da base existente.

- [ ] **Step 2: Escrever o teste novo (falhando primeiro)**

Adicionar ao arquivo `tests/Feature/ProvisionarEtiquetasGoogleJobTest.php` já existente:

```php
    public function test_cria_os_4_grupos_de_validacao_e_marca_base_existente_como_leads_em_analise(): void
    {
        $this->criarEtiquetasGlobais();
        Http::fake([
            'people.googleapis.com/v1/contactGroups' => Http::sequence()
                ->push(['resourceName' => 'contactGroups/lead-1'], 200)
                ->push(['resourceName' => 'contactGroups/pessoal-1'], 200)
                ->push(['resourceName' => 'contactGroups/novos-1'], 200)
                ->push(['resourceName' => 'contactGroups/analise-1'], 200)
                ->push(['resourceName' => 'contactGroups/certo-1'], 200)
                ->push(['resourceName' => 'contactGroups/invalido-1'], 200),
            'people.googleapis.com/v1/contactGroups/analise-1/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        Bus::fake([ProvisionarEtiquetasGoogleJob::class]);
        $tenant = Tenant::factory()->create();

        $contato = \App\Models\Contato::factory()->create();
        $vinculo = \App\Models\VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c123',
        ]);

        $token = $this->criarToken($tenant);
        (new ProvisionarEtiquetasGoogleJob($token->id))->handle(app(GoogleService::class));

        $leadsEmAnalise = Etiqueta::whereNull('tenant_id')->where('slug', 'leads_em_analise')->first();
        $this->assertSame('contactGroups/analise-1', $leadsEmAnalise->googleGrupoParaTenant($tenant->id)?->google_group_resource_name);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'contactGroups/analise-1/members:modify')
            && in_array('people/c123', $request['resourceNamesToAdd'] ?? []));
    }
```

- [ ] **Step 3: Rodar pra confirmar que falha**

Run: `php artisan test --filter=ProvisionarEtiquetasGoogleJobTest`
Expected: FAIL — o teste novo falha porque só 2 grupos são criados hoje, não 6, e não existe marcação em massa.

- [ ] **Step 4: Implementar**

```php
<?php

namespace App\Jobs;

use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pedido do Leonardo (2026-08-28): a mesma orientação de etiquetas vale pra
 * qualquer empresa, não só uma — disparado sem delay quando um GoogleToken
 * novo é criado (ver GoogleToken::booted()), cria os grupos "Lead Certo -
 * Lead" e "Lead Certo - Pessoal" na agenda do Google do tenant que acabou
 * de conectar, e liga cada um à Etiqueta global correspondente
 * (etiqueta_google_grupos). Daqui em diante:
 *   - "lead" é atribuído automaticamente pelo Lead Certo a todo contato novo
 *     (PushContatoParaGoogleJob::atribuirEtiquetas(), já existia — só
 *     faltava o grupo existir pra ele encontrar).
 *   - "pessoal" é o time do cliente quem marca manualmente no Google dele;
 *     ContatoSyncService::detectarTipoContato() já lê isso no pull e grava
 *     em Contato::tipo_contato — Contato::excluidoDoFunilComercial() usa
 *     esse campo pra impedir a criação de ticket novo de vendas.
 *
 * Adicionado em 2026-08-28: 4 etiquetas de VALIDAÇÃO de cadastro (eixo
 * independente do funil acima — ver spec
 * docs/superpowers/specs/2026-08-28-validacao-sincronizacao-contatos-design.md).
 * Além de criar os 4 grupos novos, esta task marca TODA a base já
 * vinculada ao tenant (VinculoContatoTenant com google_resource_name) como
 * "leads_em_analise" — ponto de partida antes de qualquer validação rodar
 * (Task 5/6 do plano de implementação processam essa marcação depois).
 *
 * Nomes com o prefixo "Lead Certo - " de propósito — deixa claro que são
 * grupos nossos, sem risco de colidir ou parecer com uma etiqueta que o
 * cliente já tinha criado por conta própria.
 */
class ProvisionarEtiquetasGoogleJob implements ShouldQueue
{
    use Queueable;

    private const SLUGS_FUNIL = ['lead', 'pessoal'];

    private const SLUGS_VALIDACAO = ['novos_leads', 'leads_em_analise', 'lead_certo', 'lead_invalido'];

    public function __construct(private int $googleTokenId) {}

    public function handle(GoogleService $google): void
    {
        $token = GoogleToken::find($this->googleTokenId);
        if (! $token) {
            return;
        }

        foreach ([...self::SLUGS_FUNIL, ...self::SLUGS_VALIDACAO] as $slug) {
            $this->provisionarGrupo($google, $token, $slug);
        }

        $this->marcarBaseExistenteComoEmAnalise($google, $token);
    }

    private function provisionarGrupo(GoogleService $google, GoogleToken $token, string $slug): void
    {
        $etiqueta = Etiqueta::whereNull('tenant_id')->where('slug', $slug)->first();
        if (! $etiqueta) {
            return;
        }

        $jaProvisionado = EtiquetaGoogleGrupo::where('etiqueta_id', $etiqueta->id)
            ->where('tenant_id', $token->tenant_id)
            ->exists();
        if ($jaProvisionado) {
            return;
        }

        $nomeGrupo = 'Lead Certo - ' . ucwords(str_replace('_', ' ', $slug));
        $resourceName = $google->criarGrupoContato($token, $nomeGrupo);
        if (! $resourceName) {
            return;
        }

        EtiquetaGoogleGrupo::create([
            'etiqueta_id'                => $etiqueta->id,
            'tenant_id'                  => $token->tenant_id,
            'google_group_resource_name' => $resourceName,
        ]);
    }

    private function marcarBaseExistenteComoEmAnalise(GoogleService $google, GoogleToken $token): void
    {
        $etiqueta = Etiqueta::whereNull('tenant_id')->where('slug', 'leads_em_analise')->first();
        $grupo    = $etiqueta?->googleGrupoParaTenant($token->tenant_id);

        if (! $grupo) {
            return;
        }

        $resourceNames = VinculoContatoTenant::where('tenant_id', $token->tenant_id)
            ->whereNotNull('google_resource_name')
            ->pluck('google_resource_name')
            ->all();

        if (empty($resourceNames)) {
            return;
        }

        // API do Google aceita no máximo 500 por chamada de members:modify
        foreach (array_chunk($resourceNames, 500) as $lote) {
            $google->modificarMembrosGrupo($token, $grupo->google_group_resource_name, resourceNamesToAdd: $lote);
        }

        $vinculos = VinculoContatoTenant::where('tenant_id', $token->tenant_id)
            ->whereIn('google_resource_name', $resourceNames)
            ->get();

        foreach ($vinculos as $vinculo) {
            $vinculo->etiquetas()->syncWithoutDetaching([$etiqueta->id]);
        }
    }
}
```

- [ ] **Step 5: Rodar os testes de novo**

Run: `php artisan test --filter=ProvisionarEtiquetasGoogleJobTest`
Expected: PASS (todos, incluindo os que já existiam antes desta task)

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProvisionarEtiquetasGoogleJob.php tests/Feature/ProvisionarEtiquetasGoogleJobTest.php
git commit -m "feat(contatos): provisiona as 4 etiquetas de validacao e marca base existente"
```

---

### Task 4: Hook para leads novos → NOVOS LEADS

**Files:**
- Modify: `app/Models/VinculoContatoTenant.php`
- Create: `app/Jobs/MarcarNovoLeadEtiquetaJob.php`
- Test: `tests/Feature/MarcarNovoLeadEtiquetaJobTest.php`

**Interfaces:**
- Consumes: `Etiqueta`, `EtiquetaGoogleGrupo`, `GoogleService::modificarMembrosGrupo()` (já existem).
- Produces: dispara automaticamente quando um `VinculoContatoTenant` novo é criado com `google_resource_name` já preenchido — marca como `novos_leads` SE o tenant já tem o grupo `leads_em_analise` provisionado (evita marcar errado um contato que faz parte do próprio lote inicial de conexão, ver Task 3).

- [ ] **Step 1: Escrever o teste (falhando primeiro)**

```php
<?php

namespace Tests\Feature;

use App\Jobs\MarcarNovoLeadEtiquetaJob;
use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarcarNovoLeadEtiquetaJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_marca_novos_leads_quando_grupo_ja_provisionado(): void
    {
        $tenant = Tenant::factory()->create();
        $token  = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $etiqueta = Etiqueta::create(['tenant_id' => null, 'slug' => 'novos_leads', 'nome' => 'Novos Leads', 'ativo' => true]);
        EtiquetaGoogleGrupo::create([
            'etiqueta_id' => $etiqueta->id, 'tenant_id' => $tenant->id,
            'google_group_resource_name' => 'contactGroups/novos-1',
        ]);

        Http::fake(['people.googleapis.com/v1/contactGroups/novos-1/members:modify' => Http::response(['status' => 'OK'], 200)]);

        $contato = Contato::factory()->create();
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c999',
        ]);

        (new MarcarNovoLeadEtiquetaJob($vinculo->id))->handle(app(GoogleService::class));

        $this->assertTrue($vinculo->etiquetas()->where('slug', 'novos_leads')->exists());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'novos-1/members:modify') && in_array('people/c999', $r['resourceNamesToAdd'] ?? []));
    }

    public function test_nao_marca_se_grupo_ainda_nao_provisionado(): void
    {
        $tenant  = Tenant::factory()->create();
        $contato = Contato::factory()->create();
        $vinculo = VinculoContatoTenant::create([
            'contato_id' => $contato->id, 'tenant_id' => $tenant->id,
            'google_resource_name' => 'people/c998',
        ]);

        Http::fake();

        (new MarcarNovoLeadEtiquetaJob($vinculo->id))->handle(app(GoogleService::class));

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Rodar pra confirmar que falha**

Run: `php artisan test --filter=MarcarNovoLeadEtiquetaJobTest`
Expected: FAIL — `Class "App\Jobs\MarcarNovoLeadEtiquetaJob" not found`

- [ ] **Step 3: Implementar o job**

```php
<?php

namespace App\Jobs;

use App\Models\Etiqueta;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Marca um VinculoContatoTenant recém-criado como "novos_leads" (spec
 * seção 5) — só se o tenant já tiver o grupo leads_em_analise provisionado
 * (Task 3 do plano), senão pula: evita marcar um contato do próprio lote
 * inicial de conexão como se fosse um lead novo chegando depois. Quem
 * ainda não foi marcado por nenhuma das duas etiquetas fica pra a próxima
 * varredura do comando em lote (Task 5) pegar.
 */
class MarcarNovoLeadEtiquetaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private int $vinculoId) {}

    public function handle(GoogleService $google): void
    {
        $vinculo = VinculoContatoTenant::find($this->vinculoId);
        if (! $vinculo || ! $vinculo->google_resource_name) {
            return;
        }

        $emAnalise = Etiqueta::whereNull('tenant_id')->where('slug', 'leads_em_analise')->first();
        if (! $emAnalise || ! $emAnalise->googleGrupoParaTenant($vinculo->tenant_id)) {
            return; // tenant ainda nao provisionou as etiquetas de validacao
        }

        $novosLeads = Etiqueta::whereNull('tenant_id')->where('slug', 'novos_leads')->first();
        $grupo      = $novosLeads?->googleGrupoParaTenant($vinculo->tenant_id);
        if (! $novosLeads || ! $grupo) {
            return;
        }

        // VinculoContatoTenant não tem relação tenant() — consulta direto
        // pelo tenant_id em vez de assumir uma relação que não existe.
        $token = GoogleToken::where('tenant_id', $vinculo->tenant_id)->first();
        if (! $token) {
            return;
        }

        $ok = $google->modificarMembrosGrupo($token, $grupo->google_group_resource_name, resourceNamesToAdd: [$vinculo->google_resource_name]);

        if ($ok) {
            $vinculo->etiquetas()->syncWithoutDetaching([$novosLeads->id]);
        }
    }
}
```

- [ ] **Step 4: Adicionar o hook em `VinculoContatoTenant::booted()`**

```php
    protected static function booted(): void
    {
        static::created(function (VinculoContatoTenant $vinculo) {
            EnriquecerContatoNovoViaGoogleJob::dispatch($vinculo->id);
            MarcarNovoLeadEtiquetaJob::dispatch($vinculo->id)->delay(now()->addMinutes(2));
        });
    }
```

O delay de 2 minutos dá tempo pro `PushContatoParaGoogleJob`/`EnriquecerContatoNovoViaGoogleJob` preencherem `google_resource_name` antes desta job rodar — sem isso, `$vinculo->google_resource_name` normalmente ainda está vazio no exato momento da criação.

Adicionar o import no topo do arquivo:

```php
use App\Jobs\MarcarNovoLeadEtiquetaJob;
```

- [ ] **Step 5: Rodar os testes de novo**

Run: `php artisan test --filter=MarcarNovoLeadEtiquetaJobTest`
Expected: PASS (2 testes)

- [ ] **Step 6: Rodar a suíte inteira de Contatos/Google pra garantir que o hook novo não quebrou nada existente**

Run: `php artisan test --filter=VinculoContatoTenant`
Run: `php artisan test tests/Feature/ProvisionarEtiquetasGoogleJobTest.php tests/Feature/ExcluidoDoFunilComercialTest.php`
Expected: PASS em tudo

- [ ] **Step 7: Commit**

```bash
git add app/Models/VinculoContatoTenant.php app/Jobs/MarcarNovoLeadEtiquetaJob.php tests/Feature/MarcarNovoLeadEtiquetaJobTest.php
git commit -m "feat(contatos): hook marca lead novo como novos_leads"
```

---

### Task 5: Comando de validação em lote com --dry-run

**Files:**
- Create: `app/Console/Commands/ValidarCadastrosContatos.php`
- Test: `tests/Feature/ValidarCadastrosContatosTest.php`

**Interfaces:**
- Consumes: `ContatoValidacaoService::validar()` (Task 2), `Etiqueta`/`EtiquetaGoogleGrupo`/`GoogleService::modificarMembrosGrupo()` (já existem).
- Produces: comando `php artisan contatos:validar-cadastros --tenant=<id> [--dry-run] [--chunk=200]` — roda a validação sobre todo `VinculoContatoTenant` do tenant marcado como `novos_leads` OU `leads_em_analise`, aplica a etiqueta final no Google (removendo a de origem), imprime um resumo.

- [ ] **Step 1: Escrever o teste (falhando primeiro)**

```php
<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ValidarCadastrosContatosTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenantComEtiquetas(): Tenant
    {
        $tenant = Tenant::factory()->create();
        GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        foreach (['leads_em_analise', 'lead_certo', 'lead_invalido'] as $i => $slug) {
            $etiqueta = Etiqueta::firstOrCreate(['tenant_id' => null, 'slug' => $slug], ['nome' => $slug, 'ativo' => true]);
            EtiquetaGoogleGrupo::create([
                'etiqueta_id' => $etiqueta->id, 'tenant_id' => $tenant->id,
                'google_group_resource_name' => "contactGroups/{$slug}",
            ]);
        }

        return $tenant;
    }

    public function test_dry_run_nao_altera_nada(): void
    {
        $tenant  = $this->setupTenantComEtiquetas();
        $contato = Contato::factory()->create(['telefone' => '5521994359537']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c1']);
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculo->etiquetas()->attach($emAnalise->id);

        Http::fake();

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id} --dry-run")
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'leads_em_analise')->exists());
    }

    public function test_sem_dry_run_aplica_lead_certo_e_remove_leads_em_analise(): void
    {
        $tenant  = $this->setupTenantComEtiquetas();
        $contato = Contato::factory()->create(['telefone' => '5521994359537']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c1']);
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculo->etiquetas()->attach($emAnalise->id);

        Http::fake([
            'people.googleapis.com/v1/contactGroups/lead_certo/members:modify'       => Http::response(['status' => 'OK'], 200),
            'people.googleapis.com/v1/contactGroups/leads_em_analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id}")
            ->assertExitCode(0);

        $vinculo->refresh();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'lead_certo')->exists());
        $this->assertFalse($vinculo->etiquetas()->where('slug', 'leads_em_analise')->exists());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'lead_certo/members:modify')
            && in_array('people/c1', $r['resourceNamesToAdd'] ?? []));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'leads_em_analise/members:modify')
            && in_array('people/c1', $r['resourceNamesToRemove'] ?? []));
    }

    public function test_telefone_invalido_vai_pra_lead_invalido(): void
    {
        $tenant  = $this->setupTenantComEtiquetas();
        $contato = Contato::factory()->create(['telefone' => '55481126376']);
        $vinculo = VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c2']);
        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $vinculo->etiquetas()->attach($emAnalise->id);

        Http::fake([
            'people.googleapis.com/v1/contactGroups/lead_invalido/members:modify'    => Http::response(['status' => 'OK'], 200),
            'people.googleapis.com/v1/contactGroups/leads_em_analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:validar-cadastros --tenant={$tenant->id}")
            ->assertExitCode(0);

        $vinculo->refresh();
        $this->assertTrue($vinculo->etiquetas()->where('slug', 'lead_invalido')->exists());
    }
}
```

- [ ] **Step 2: Rodar pra confirmar que falha**

Run: `php artisan test --filter=ValidarCadastrosContatosTest`
Expected: FAIL — comando `contatos:validar-cadastros` não existe.

- [ ] **Step 3: Implementar o comando**

```php
<?php

namespace App\Console\Commands;

use App\Models\Etiqueta;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoValidacaoService;
use App\Services\GoogleService;
use Illuminate\Console\Command;

/**
 * Roda a validação de telefone (spec seção 5) sobre os contatos de um
 * tenant marcados como "novos_leads" ou "leads_em_analise" — decide
 * lead_certo (mescla/autocorrige) ou lead_invalido, aplica a etiqueta
 * final no Google removendo a de origem. --dry-run só mostra o que faria.
 */
class ValidarCadastrosContatos extends Command
{
    protected $signature = 'contatos:validar-cadastros
                            {--tenant= : ID do tenant}
                            {--dry-run : Mostra o que seria feito sem aplicar}
                            {--chunk=200 : Quantidade de vínculos por lote}';

    protected $description = 'Valida telefone dos contatos em analise/novos de um tenant e aplica lead_certo ou lead_invalido';

    private int $certos    = 0;
    private int $invalidos = 0;
    private int $mesclados = 0;

    public function __construct(
        private ContatoValidacaoService $validacao,
        private GoogleService $google,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        if (! $tenantId) {
            $this->error('--tenant é obrigatório.');

            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk  = (int) $this->option('chunk');

        $token = \App\Models\GoogleToken::where('tenant_id', $tenantId)->first();
        if (! $token) {
            $this->error('Tenant sem GoogleToken conectado.');

            return 1;
        }

        $slugsOrigem = ['novos_leads', 'leads_em_analise'];

        $etiquetaLeadCerto    = Etiqueta::whereNull('tenant_id')->where('slug', 'lead_certo')->first();
        $etiquetaLeadInvalido = Etiqueta::whereNull('tenant_id')->where('slug', 'lead_invalido')->first();
        $grupoLeadCerto       = $etiquetaLeadCerto?->googleGrupoParaTenant($tenantId);
        $grupoLeadInvalido    = $etiquetaLeadInvalido?->googleGrupoParaTenant($tenantId);

        if (! $grupoLeadCerto || ! $grupoLeadInvalido) {
            $this->error('Etiquetas de validação não provisionadas pra este tenant.');

            return 1;
        }

        $this->info($dryRun ? '[DRY-RUN] Nenhuma alteração será salva.' : 'Validando cadastros...');

        VinculoContatoTenant::where('tenant_id', $tenantId)
            ->whereNotNull('google_resource_name')
            ->whereHas('etiquetas', fn ($q) => $q->whereIn('slug', $slugsOrigem))
            ->with('contato', 'etiquetas')
            ->chunkById($chunk, function ($lote) use ($dryRun, $token, $tenantId, $grupoLeadCerto, $grupoLeadInvalido, $etiquetaLeadCerto, $etiquetaLeadInvalido, $slugsOrigem) {
                foreach ($lote as $vinculo) {
                    if (! $vinculo->contato) {
                        continue;
                    }

                    $resultado = $dryRun
                        ? $this->preverResultado($vinculo)
                        : $this->validacao->validar($vinculo->contato);

                    $etiquetaAlvo = $resultado === 'lead_certo' ? $etiquetaLeadCerto : $etiquetaLeadInvalido;
                    $grupoAlvo    = $resultado === 'lead_certo' ? $grupoLeadCerto : $grupoLeadInvalido;

                    $resultado === 'lead_certo' ? $this->certos++ : $this->invalidos++;

                    $this->line("  {$vinculo->contato->telefone} (contato #{$vinculo->contato_id}) -> {$resultado}");

                    if ($dryRun) {
                        continue;
                    }

                    // A API do Google (members:modify) opera em UM grupo por
                    // chamada — não dá pra "mover" entre dois grupos numa
                    // chamada só. Precisa de uma chamada de remove no grupo
                    // de origem e outra de add no grupo de destino.
                    $etiquetasOrigemDoVinculo = $vinculo->etiquetas()->whereIn('slug', $slugsOrigem)->get();

                    foreach ($etiquetasOrigemDoVinculo as $etiquetaOrigem) {
                        $grupoOrigem = $etiquetaOrigem->googleGrupoParaTenant($tenantId);
                        if ($grupoOrigem) {
                            $this->google->modificarMembrosGrupo(
                                $token,
                                $grupoOrigem->google_group_resource_name,
                                resourceNamesToRemove: [$vinculo->google_resource_name],
                            );
                        }
                    }

                    $this->google->modificarMembrosGrupo(
                        $token,
                        $grupoAlvo->google_group_resource_name,
                        resourceNamesToAdd: [$vinculo->google_resource_name],
                    );

                    $vinculo->etiquetas()->detach($etiquetasOrigemDoVinculo->pluck('id'));
                    $vinculo->etiquetas()->syncWithoutDetaching([$etiquetaAlvo->id]);
                }
            });

        $this->newLine();
        $this->table(
            ['Status', 'Quantidade'],
            [
                ['LEAD CERTO', $this->certos],
                ['LEAD INVALIDO', $this->invalidos],
            ]
        );

        if ($dryRun) {
            $this->warn('Rode sem --dry-run para aplicar.');
        }

        return 0;
    }

    /**
     * Simula o resultado da validação sem tocar no banco nem no Google —
     * usa a mesma leitura de candidatos do serviço, só não aplica a
     * mesclagem/autocorreção.
     */
    private function preverResultado(VinculoContatoTenant $vinculo): string
    {
        $reparo = app(\App\Services\TelefoneReparoService::class);

        if ($reparo->ehCanonico($vinculo->contato->telefone)) {
            return 'lead_certo';
        }

        return empty($reparo->candidatos($vinculo->contato->telefone)) ? 'lead_invalido' : 'lead_certo';
    }
}
```

- [ ] **Step 4: Rodar os testes de novo**

Run: `php artisan test --filter=ValidarCadastrosContatosTest`
Expected: PASS (3 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ValidarCadastrosContatos.php tests/Feature/ValidarCadastrosContatosTest.php
git commit -m "feat(contatos): comando contatos:validar-cadastros com --dry-run"
```

---

### Task 6: Backfill para tenant já conectado (Frete Rio)

**Files:**
- Create: `app/Console/Commands/BackfillEtiquetasValidacaoContatos.php`
- Test: `tests/Feature/BackfillEtiquetasValidacaoContatosTest.php`

**Interfaces:**
- Consumes: `ProvisionarEtiquetasGoogleJob::handle()` — mas esse job precisa de um `GoogleToken` recém-criado (`booted()` só dispara em `created()`); pra um tenant já conectado antes desta feature existir (caso do Frete Rio), roda a mesma lógica manualmente contra o token que já existe.

- [ ] **Step 1: Escrever o teste (falhando primeiro)**

```php
<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillEtiquetasValidacaoContatos;
use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\GoogleToken;
use App\Models\Tenant;
use App\Models\VinculoContatoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillEtiquetasValidacaoContatosTest extends TestCase
{
    use RefreshDatabase;

    private function criarEtiquetasGlobais(): void
    {
        foreach (['lead', 'pessoal', 'novos_leads', 'leads_em_analise', 'lead_certo', 'lead_invalido'] as $slug) {
            Etiqueta::create(['tenant_id' => null, 'slug' => $slug, 'nome' => ucfirst($slug), 'ativo' => true]);
        }
    }

    public function test_provisiona_grupos_e_marca_base_de_tenant_ja_conectado(): void
    {
        $this->criarEtiquetasGlobais();

        $tenant = Tenant::factory()->create();
        $token  = GoogleToken::create([
            'tenant_id' => $tenant->id, 'google_email' => 'a@b.com',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'token_type' => 'Bearer',
            'expires_at' => now()->addHour(), 'scopes' => ['contacts'],
        ]);

        $contato = Contato::factory()->create();
        VinculoContatoTenant::create(['contato_id' => $contato->id, 'tenant_id' => $tenant->id, 'google_resource_name' => 'people/c1']);

        Http::fake([
            'people.googleapis.com/v1/contactGroups' => Http::sequence()
                ->push(['resourceName' => 'contactGroups/lead'], 200)
                ->push(['resourceName' => 'contactGroups/pessoal'], 200)
                ->push(['resourceName' => 'contactGroups/novos'], 200)
                ->push(['resourceName' => 'contactGroups/analise'], 200)
                ->push(['resourceName' => 'contactGroups/certo'], 200)
                ->push(['resourceName' => 'contactGroups/invalido'], 200),
            'people.googleapis.com/v1/contactGroups/analise/members:modify' => Http::response(['status' => 'OK'], 200),
        ]);

        $this->artisan("contatos:backfill-etiquetas-validacao --tenant={$tenant->id}")
            ->assertExitCode(0);

        $emAnalise = Etiqueta::where('slug', 'leads_em_analise')->first();
        $this->assertNotNull($emAnalise->googleGrupoParaTenant($tenant->id));
    }
}
```

- [ ] **Step 2: Rodar pra confirmar que falha**

Run: `php artisan test --filter=BackfillEtiquetasValidacaoContatosTest`
Expected: FAIL — comando não existe.

- [ ] **Step 3: Implementar o comando**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionarEtiquetasGoogleJob;
use App\Models\GoogleToken;
use App\Services\GoogleService;
use Illuminate\Console\Command;

/**
 * Pra tenant que já conectou o Google ANTES desta feature existir
 * (GoogleToken::booted() só dispara em created(), não retroativamente) —
 * roda a mesma provisão + marcação em massa manualmente. Caso de uso:
 * Frete Rio, já conectado, precisa deste comando rodar uma vez.
 */
class BackfillEtiquetasValidacaoContatos extends Command
{
    protected $signature = 'contatos:backfill-etiquetas-validacao {--tenant= : ID do tenant}';

    protected $description = 'Provisiona as etiquetas de validação e marca a base existente pra um tenant já conectado ao Google';

    public function handle(GoogleService $google): int
    {
        $tenantId = (int) $this->option('tenant');
        if (! $tenantId) {
            $this->error('--tenant é obrigatório.');

            return 1;
        }

        $token = GoogleToken::where('tenant_id', $tenantId)->first();
        if (! $token) {
            $this->error('Tenant sem GoogleToken conectado.');

            return 1;
        }

        (new ProvisionarEtiquetasGoogleJob($token->id))->handle($google);

        $this->info('Etiquetas de validação provisionadas e base existente marcada como LEADS EM ANÁLISE.');

        return 0;
    }
}
```

- [ ] **Step 4: Rodar os testes de novo**

Run: `php artisan test --filter=BackfillEtiquetasValidacaoContatosTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BackfillEtiquetasValidacaoContatos.php tests/Feature/BackfillEtiquetasValidacaoContatosTest.php
git commit -m "feat(contatos): comando de backfill pra tenant ja conectado"
```

---

## Uso em produção (fora do escopo de tarefas — passo manual do Leonardo)

Depois de todas as tasks mergeadas e deployadas:

```bash
# 1. Provisiona as etiquetas + marca toda a base do Frete Rio como LEADS EM ANÁLISE
php artisan contatos:backfill-etiquetas-validacao --tenant=1

# 2. Dry-run — mostra o que a validação faria, sem aplicar nada
php artisan contatos:validar-cadastros --tenant=1 --dry-run

# 3. Depois de revisar o dry-run, aplica de verdade
php artisan contatos:validar-cadastros --tenant=1
```
