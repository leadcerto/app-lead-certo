<?php

namespace Tests\Feature;

use App\Models\ImagemMascara;
use App\Models\Tenant;
use App\Services\ImagemComposicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImagemComposicaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarMascaraDeTeste(int $largura, int $altura, int $janelaX, int $janelaY, int $janelaLargura, int $janelaAltura): array
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        imagesavealpha($imagem, true);
        imagealphablending($imagem, false);

        $vermelho = imagecolorallocatealpha($imagem, 200, 0, 0, 0);
        $transparente = imagecolorallocatealpha($imagem, 0, 0, 0, 127);

        imagefill($imagem, 0, 0, $vermelho);
        imagefilledrectangle($imagem, $janelaX, $janelaY, $janelaX + $janelaLargura - 1, $janelaY + $janelaAltura - 1, $transparente);

        ob_start();
        imagepng($imagem);
        $bytes = ob_get_clean();
        imagedestroy($imagem);

        Storage::fake('public');
        Storage::disk('public')->put('mascaras-teste/mascara.png', $bytes);

        return [
            'url' => Storage::disk('public')->url('mascaras-teste/mascara.png'),
        ];
    }

    private function criarFotoDeTeste(int $largura, int $altura, int $r, int $g, int $b): string
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        $cor = imagecolorallocate($imagem, $r, $g, $b);
        imagefill($imagem, 0, 0, $cor);

        $caminho = tempnam(sys_get_temp_dir(), 'foto_teste_') . '.png';
        imagepng($imagem, $caminho);
        imagedestroy($imagem);

        return $caminho;
    }

    /**
     * Confirma as duas garantias centrais do pedido do Leonardo: (1) a área
     * fora da janela (a "marca" — cabeçalho/rodapé) sai IDÊNTICA à máscara
     * original, nunca alterada; (2) a área dentro da janela mostra a foto de
     * fundo, não a máscara.
     */
    public function test_compoe_foto_de_fundo_com_mascara_preservando_a_marca(): void
    {
        $tenant = Tenant::factory()->create();
        $mascaraArquivo = $this->criarMascaraDeTeste(200, 150, 20, 30, 100, 80);

        $mascara = ImagemMascara::create([
            'tenant_id'      => $tenant->id,
            'nome'           => 'Teste',
            'arquivo_url'    => $mascaraArquivo['url'],
            'janela_x'       => 20,
            'janela_y'       => 30,
            'janela_largura' => 100,
            'janela_altura'  => 80,
            'largura_total'  => 200,
            'altura_total'   => 150,
        ]);

        $fotoFundo = $this->criarFotoDeTeste(400, 400, 0, 200, 0); // verde puro

        $resultadoBytes = app(ImagemComposicaoService::class)->compor($fotoFundo, $mascara);

        $imagemResultado = imagecreatefromstring($resultadoBytes);
        $this->assertSame(200, imagesx($imagemResultado));
        $this->assertSame(150, imagesy($imagemResultado));

        // Fora da janela (canto superior esquerdo, dentro da faixa vermelha da máscara): continua vermelho.
        $corForaDaJanela = imagecolorsforindex($imagemResultado, imagecolorat($imagemResultado, 5, 5));
        $this->assertSame(200, $corForaDaJanela['red']);
        $this->assertSame(0, $corForaDaJanela['green']);
        $this->assertSame(0, $corForaDaJanela['blue']);

        // Dentro da janela (centro): é a foto de fundo verde, não a máscara.
        $corDentroDaJanela = imagecolorsforindex($imagemResultado, imagecolorat($imagemResultado, 70, 70));
        $this->assertSame(0, $corDentroDaJanela['red']);
        $this->assertSame(200, $corDentroDaJanela['green']);
        $this->assertSame(0, $corDentroDaJanela['blue']);

        imagedestroy($imagemResultado);
        unlink($fotoFundo);
    }
}
