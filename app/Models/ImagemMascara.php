<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Máscara de marca (cabeçalho/rodapé fixos com logo, WhatsApp etc.) aplicada
 * por cima de uma foto de fundo pra gerar uma imagem pronta pra postar. A
 * máscara em si é um PNG com uma janela transparente — o texto/número/ícone
 * de marca nunca são desenhados por código, só existem no PNG que o usuário
 * fornece, então nunca saem errados.
 */
class ImagemMascara extends Model
{
    protected $table = 'imagem_mascaras';

    protected $fillable = [
        'tenant_id',
        'nome',
        'arquivo_url',
        'janela_x',
        'janela_y',
        'janela_largura',
        'janela_altura',
        'largura_total',
        'altura_total',
        'ativo',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Varre o canal alfa de um PNG e devolve o bounding box da maior área
     * totalmente transparente (alpha = 127, GD usa 0-127) — essa área é onde
     * a foto de fundo vai entrar. Lança exceção se não achar nenhum pixel
     * transparente (o usuário mandou uma máscara sem janela de verdade).
     *
     * @return array{x:int,y:int,largura:int,altura:int,largura_total:int,altura_total:int}
     */
    public static function detectarJanelaTransparente(string $caminhoAbsolutoPng): array
    {
        $imagem = @imagecreatefrompng($caminhoAbsolutoPng);
        if (! $imagem) {
            throw new \RuntimeException('Não foi possível ler o PNG da máscara.');
        }

        imagealphablending($imagem, false);
        imagesavealpha($imagem, true);

        $larguraTotal = imagesx($imagem);
        $alturaTotal  = imagesy($imagem);

        $minX = $larguraTotal;
        $minY = $alturaTotal;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $alturaTotal; $y++) {
            for ($x = 0; $x < $larguraTotal; $x++) {
                $rgba  = imagecolorat($imagem, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F; // 0 = opaco, 127 = totalmente transparente no GD

                if ($alpha === 127) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        imagedestroy($imagem);

        if ($maxX < $minX || $maxY < $minY) {
            throw new \RuntimeException('Essa máscara não tem nenhuma área transparente — confirme se o PNG foi exportado com canal alfa.');
        }

        return [
            'x'              => $minX,
            'y'              => $minY,
            'largura'        => $maxX - $minX + 1,
            'altura'         => $maxY - $minY + 1,
            'largura_total'  => $larguraTotal,
            'altura_total'   => $alturaTotal,
        ];
    }
}
