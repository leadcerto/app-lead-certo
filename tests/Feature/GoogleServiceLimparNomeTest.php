<?php

namespace Tests\Feature;

use App\Services\GoogleService;
use Tests\TestCase;

/**
 * Fix round 2 (task 5, re-review): GoogleService::limparNome() é o método
 * compartilhado usado pelo caminho de push (criarContato()/enriquecerContato())
 * já em produção. Esse teste cobre diretamente o comportamento original —
 * trim(' -._*~+#@!') — que ficou sem cobertura dedicada até aqui.
 */
class GoogleServiceLimparNomeTest extends TestCase
{
    public function test_remove_pontuacao_de_borda_no_inicio_e_fim(): void
    {
        $service = app(GoogleService::class);

        $this->assertSame('Maria', $service->limparNome('- Maria'));
        $this->assertSame('Maria', $service->limparNome('Maria -'));
        $this->assertSame('Maria', $service->limparNome('...Maria***'));
        $this->assertSame('Maria', $service->limparNome('  Maria  '));
    }

    public function test_normaliza_espacos_multiplos_e_aplica_title_case(): void
    {
        $service = app(GoogleService::class);

        $this->assertSame('Maria Silva', $service->limparNome('maria   silva'));
        $this->assertSame('João Da Silva', $service->limparNome('joão da silva'));
    }
}
