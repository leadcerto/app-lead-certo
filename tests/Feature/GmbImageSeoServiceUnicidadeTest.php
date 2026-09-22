<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\GmbImageSeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Achado real 2026-09-22 (Leonardo, upload em lote na Galeria de Imagens do GMB):
 * subiu 6 imagens diferentes de uma vez e as 6 apareceram na galeria com a MESMA
 * foto. Causa: gerarNomeSeo() só usa data/hora até o MINUTO
 * (`$dataHora->format('H\hi')`) — sem segundo, sem componente único por arquivo.
 * Como GmbPostController::storeImagem() chama isso num loop com os mesmos
 * tenant/tema pra cada arquivo do lote, todos os arquivos do MESMO minuto geram
 * o nome IDÊNTICO — cada storeAs() subsequente sobrescreve o arquivo físico
 * anterior no disco, mesmo cada um virando uma linha separada em GmbPostImagem.
 */
class GmbImageSeoServiceUnicidadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_nomes_gerados_no_mesmo_minuto_para_o_mesmo_tenant_sao_unicos(): void
    {
        $tenant   = Tenant::factory()->create(['nome' => 'Frete Rio', 'nicho' => 'frete']);
        $dataHora = Carbon::create(2026, 9, 22, 16, 43, 0);

        $service = app(GmbImageSeoService::class);

        $nomes = [];
        for ($i = 0; $i < 6; $i++) {
            $nomes[] = $service->gerarNomeSeo($tenant, null, $dataHora, 'png', null);
        }

        $this->assertCount(
            6,
            array_unique($nomes),
            'seis chamadas com o mesmo tenant/data/tema (mesmo minuto) geraram nomes duplicados — arquivo se sobrescreve no disco'
        );
    }

    /**
     * salvarImagemBytes() é a variante pra conteúdo que não chega como upload
     * de formulário (resultado de composição de máscara, geração por IA).
     */
    public function test_salvar_imagem_bytes_grava_no_disco_e_retorna_url_publica(): void
    {
        Storage::fake('public');

        $tenant = Tenant::factory()->create(['nome' => 'Frete Rio', 'nicho' => 'frete']);
        $bytes  = 'conteudo-fake-de-imagem-pra-teste';

        $url = app(GmbImageSeoService::class)->salvarImagemBytes($bytes, $tenant, null, 'png');

        $this->assertStringContainsString('/storage/gmb-posts/', $url);

        $caminhoRelativo = str_replace(Storage::disk('public')->url(''), '', $url);
        Storage::disk('public')->assertExists($caminhoRelativo);
        $this->assertSame($bytes, Storage::disk('public')->get($caminhoRelativo));
    }
}
