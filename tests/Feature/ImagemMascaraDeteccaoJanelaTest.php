<?php

namespace Tests\Feature;

use App\Models\ImagemMascara;
use Tests\TestCase;

/**
 * Achado real 2026-09-22 (Leonardo, projeto Banco de Imagens com Máscara):
 * a máscara é um PNG com janela transparente — o texto/número/ícone de marca
 * nunca são desenhados por código, só existem no PNG. Pra saber onde colar a
 * foto de fundo, o sistema precisa achar sozinho o retângulo transparente.
 */
class ImagemMascaraDeteccaoJanelaTest extends TestCase
{
    private function criarPngComJanelaTransparente(int $largura, int $altura, int $janelaX, int $janelaY, int $janelaLargura, int $janelaAltura): string
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        imagesavealpha($imagem, true);
        imagealphablending($imagem, false);

        $vermelho = imagecolorallocatealpha($imagem, 200, 0, 0, 0); // opaco
        $transparente = imagecolorallocatealpha($imagem, 0, 0, 0, 127); // totalmente transparente

        imagefill($imagem, 0, 0, $vermelho);
        imagefilledrectangle($imagem, $janelaX, $janelaY, $janelaX + $janelaLargura - 1, $janelaY + $janelaAltura - 1, $transparente);

        $caminho = tempnam(sys_get_temp_dir(), 'mascara_teste_') . '.png';
        imagepng($imagem, $caminho);
        imagedestroy($imagem);

        return $caminho;
    }

    public function test_detecta_janela_transparente_no_meio_da_imagem(): void
    {
        $caminho = $this->criarPngComJanelaTransparente(200, 150, 20, 30, 100, 80);

        $janela = ImagemMascara::detectarJanelaTransparente($caminho);

        $this->assertSame(20, $janela['x']);
        $this->assertSame(30, $janela['y']);
        $this->assertSame(100, $janela['largura']);
        $this->assertSame(80, $janela['altura']);
        $this->assertSame(200, $janela['largura_total']);
        $this->assertSame(150, $janela['altura_total']);

        unlink($caminho);
    }

    public function test_estoura_excecao_quando_nao_ha_area_transparente(): void
    {
        $imagem = imagecreatetruecolor(100, 100);
        $vermelho = imagecolorallocate($imagem, 200, 0, 0);
        imagefill($imagem, 0, 0, $vermelho);
        $caminho = tempnam(sys_get_temp_dir(), 'mascara_sem_janela_') . '.png';
        imagepng($imagem, $caminho);
        imagedestroy($imagem);

        $this->expectException(\RuntimeException::class);

        ImagemMascara::detectarJanelaTransparente($caminho);

        unlink($caminho);
    }
}
