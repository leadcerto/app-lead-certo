<?php

namespace App\Services;

use App\Models\ImagemMascara;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;

/**
 * Compõe uma foto de fundo com uma máscara de marca (ImagemMascara) e devolve
 * o PNG final pronto pra postar. Nunca desenha texto/número por código — a
 * máscara já é um PNG pronto (cabeçalho/rodapé/ícone fixos, janela
 * transparente onde a foto entra), então a marca nunca sai errada.
 */
class ImagemComposicaoService
{
    public function compor(string $caminhoFotoFundo, ImagemMascara $mascara): string
    {
        $manager = ImageManager::usingDriver(GdDriver::class);

        $caminhoMascaraAbsoluto = $this->caminhoAbsoluto($mascara->arquivo_url);

        // A foto de fundo preenche o canvas inteiro da máscara (cover = corta
        // e redimensiona pra cobrir sem distorcer); a máscara é inserida por
        // cima — sua área opaca (cabeçalho/rodapé) cobre a foto normalmente,
        // e a janela transparente deixa a foto aparecer por baixo.
        $imagemFinal = $manager->decodePath($caminhoFotoFundo)
            ->cover($mascara->largura_total, $mascara->altura_total)
            ->insert($manager->decodePath($caminhoMascaraAbsoluto), 0, 0);

        return $imagemFinal->encode(new PngEncoder())->toString();
    }

    private function caminhoAbsoluto(string $url): string
    {
        $urlStorage = Storage::disk('public')->url('');
        $caminhoRelativo = str_starts_with($url, $urlStorage)
            ? str_replace($urlStorage, '', $url)
            : $url;

        return Storage::disk('public')->path($caminhoRelativo);
    }
}
