<?php

namespace App\Services;

use App\Models\GmbPost;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GmbImageSeoService
{
    /**
     * Gera o nome de arquivo otimizado para SEO no Google Meu Negócio.
     * Exemplo: frete-rio-mudancas-transportes-barra-da-tijuca-rio-de-janeiro-2026-09-01-10h30.jpg
     */
    public function gerarNomeSeo(Tenant $tenant, ?PerfilGmb $perfil, Carbon $dataHora, string $extensao = 'jpg', ?string $tema = null): string
    {
        $partes = [];

        // 1. Nome da Empresa
        $partes[] = Str::slug($tenant->nome ?? 'empresa');

        // 2. Palavras-chave do Nicho / Posicionamento
        $nicho = $tenant->nicho ?? 'servicos';
        $palavrasNicho = match (strtolower($nicho)) {
            'frete', 'mudancas', 'frete_rio' => 'mudancas-fretes-transportes',
            'imoveis', 'imobiliaria', 'caixa' => 'imoveis-leilao-caixa-financiamento',
            'pizza', 'pizzaria', 'restaurante' => 'pizza-delivery-artesanal',
            'advocacia', 'juridico' => 'advogado-assessoria-juridica',
            'estetica', 'beleza' => 'estetica-beleza-cuidados',
            default => 'atendimento-servicos-qualidade',
        };

        if ($tema) {
            $partes[] = Str::slug($tema);
        } else {
            $partes[] = $palavrasNicho;
        }

        // 3. Localização (Bairro e Cidade)
        if ($perfil) {
            $partes[] = Str::slug($perfil->nome);
            if (!empty($perfil->city)) {
                $partes[] = Str::slug($perfil->city);
            }
        }

        // 4. Data e Hora da Postagem
        $dataFormatada = $dataHora->format('Y-m-d') . '-' . $dataHora->format('H\hi');
        $partes[] = $dataFormatada;

        $nomeBase = implode('-', array_filter($partes));
        // Remove hífens duplicados
        $nomeBase = preg_replace('/-+/', '-', $nomeBase);

        return "{$nomeBase}.{$extensao}";
    }

    /**
     * Processa o upload de uma imagem aplicando o nome otimizado de SEO.
     */
    public function salvarImagemSeo(UploadedFile $arquivo, Tenant $tenant, ?PerfilGmb $perfil, ?Carbon $dataHora = null, ?string $tema = null): string
    {
        $dataHora = $dataHora ?: now();
        $extensao = strtolower($arquivo->getClientOriginalExtension()) ?: 'jpg';
        $nomeArquivo = $this->gerarNomeSeo($tenant, $perfil, $dataHora, $extensao, $tema);

        // Armazena no disco public em gmb-posts/ANO/MES/
        $pasta = 'gmb-posts/' . $dataHora->format('Y/m');
        $caminho = $arquivo->storeAs($pasta, $nomeArquivo, 'public');

        return Storage::disk('public')->url($caminho);
    }

    /**
     * Prepara e renomeia uma imagem de um post antes da publicação para garantir SEO máximo.
     */
    public function prepararImagemParaPost(GmbPost $post): void
    {
        if (empty($post->imagem_url)) {
            return;
        }

        $tenant = $post->tenant;
        $perfil = $post->perfil;
        $dataHora = $post->data_agendada ?: now();

        // Se já estiver com o padrão de data no nome, não precisa renomear
        $dataStr = $dataHora->format('Y-m-d');
        if (str_contains($post->imagem_url, $dataStr)) {
            return;
        }

        // Se a imagem for local no storage
        $urlStorage = Storage::disk('public')->url('');
        if (str_starts_with($post->imagem_url, $urlStorage)) {
            $caminhoRelativo = str_replace($urlStorage, '', $post->imagem_url);
            if (Storage::disk('public')->exists($caminhoRelativo)) {
                $extensao = pathinfo($caminhoRelativo, PATHINFO_EXTENSION) ?: 'jpg';
                $novoNome = $this->gerarNomeSeo($tenant, $perfil, $dataHora, $extensao, $post->titulo);
                $novaPasta = 'gmb-posts/' . $dataHora->format('Y/m');
                $novoCaminho = "{$novaPasta}/{$novoNome}";

                Storage::disk('public')->move($caminhoRelativo, $novoCaminho);
                $post->update([
                    'imagem_url' => Storage::disk('public')->url($novoCaminho),
                ]);
            }
        }
    }
}
