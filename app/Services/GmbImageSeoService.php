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
     * Estrutura: {empresa}-{palavras-chave}-{bairro-cidade}-{data-e-hora}.{ext}
     * Exemplo: frete-rio-mudancas-fretes-transportes-barra-da-tijuca-rio-de-janeiro-2026-09-01-10h30.png
     */
    public function gerarNomeSeo(Tenant $tenant, ?PerfilGmb $perfil, ?Carbon $dataHora = null, string $extensao = 'jpg', ?string $tema = null): string
    {
        // Garante horário local do Brasil (America/Sao_Paulo)
        $dataHora = $dataHora ? $dataHora->copy()->setTimezone('America/Sao_Paulo') : now('America/Sao_Paulo');

        $partes = [];

        // 1. Nome da Empresa (Slug)
        $partes[] = Str::slug($tenant->nome ?? 'empresa');

        // 2. Palavras-chave do Nicho / Serviço
        $nicho = $tenant->nicho ?? 'servicos';
        $palavrasNicho = match (strtolower($nicho)) {
            'frete', 'mudancas', 'frete_rio' => 'mudancas-fretes-transportes',
            'imoveis', 'imobiliaria', 'caixa' => 'imoveis-leilao-caixa-financiamento',
            'pizza', 'pizzaria', 'restaurante' => 'pizza-delivery-artesanal',
            'advocacia', 'juridico' => 'advogado-assessoria-juridica',
            'estetica', 'beleza' => 'estetica-beleza-cuidados',
            default => 'atendimento-servicos-qualidade',
        };

        if (!empty($tema)) {
            $partes[] = Str::slug($tema);
        } else {
            $partes[] = $palavrasNicho;
        }

        // 3. Localização da Ficha (Bairro e Cidade)
        if ($perfil) {
            $partes[] = Str::slug($perfil->nome);
            if (!empty($perfil->city) && !str_contains(strtolower($perfil->nome), strtolower($perfil->city))) {
                $partes[] = Str::slug($perfil->city);
            }
        }

        // 4. Data e Hora Exata da Postagem (sempre após as palavras-chave)
        $dataFormatada = $dataHora->format('Y-m-d') . '-' . $dataHora->format('H\hi');
        $partes[] = $dataFormatada;

        $nomeBase = implode('-', array_filter($partes));
        // Remove hífens duplicados
        $nomeBase = preg_replace('/-+/', '-', $nomeBase);

        // Achado real 2026-09-22 (Leonardo, upload em lote): a data/hora só tem
        // precisão de MINUTO — várias imagens do mesmo lote (mesmo tenant/tema,
        // mesmo minuto) geravam o nome IDÊNTICO, e cada storeAs() subsequente
        // sobrescrevia o arquivo físico anterior no disco. Sufixo curto e único
        // por chamada garante que nenhum upload no mesmo lote colida, sem afetar
        // as palavras-chave de SEO (fica só no final do nome).
        $sufixoUnico = substr(uniqid(), -6);

        return "{$nomeBase}-{$sufixoUnico}.{$extensao}";
    }

    /**
     * Mesma coisa que salvarImagemSeo(), mas pra conteúdo que não chegou como
     * upload de formulário — resultado de composição de máscara ou de geração
     * por IA, que já vêm como bytes crus em memória.
     */
    public function salvarImagemBytes(string $bytes, Tenant $tenant, ?PerfilGmb $perfil, ?string $extensao = 'png', ?Carbon $dataHora = null, ?string $tema = null, string $pasta = 'gmb-posts'): string
    {
        $dataHora = $dataHora ? $dataHora->copy()->setTimezone('America/Sao_Paulo') : now('America/Sao_Paulo');
        $nomeArquivo = $this->gerarNomeSeo($tenant, $perfil, $dataHora, $extensao ?: 'png', $tema);

        $caminho = "{$pasta}/{$nomeArquivo}";
        Storage::disk('public')->put($caminho, $bytes);

        return Storage::disk('public')->url($caminho);
    }

    /**
     * Processa o upload de uma imagem aplicando o nome otimizado de SEO.
     */
    public function salvarImagemSeo(UploadedFile $arquivo, Tenant $tenant, ?PerfilGmb $perfil, ?Carbon $dataHora = null, ?string $tema = null): string
    {
        $dataHora = $dataHora ? $dataHora->copy()->setTimezone('America/Sao_Paulo') : now('America/Sao_Paulo');
        $extensao = strtolower($arquivo->getClientOriginalExtension()) ?: 'jpg';
        $nomeArquivo = $this->gerarNomeSeo($tenant, $perfil, $dataHora, $extensao, $tema);

        // Armazena no disco public em gmb-posts/ANO/MES/
        $pasta = 'gmb-posts/' . $dataHora->format('Y/m');
        $caminho = $arquivo->storeAs($pasta, $nomeArquivo, 'public');

        return Storage::disk('public')->url($caminho);
    }

    /**
     * Prepara e renomeia uma imagem de um post antes da publicação para garantir SEO máximo
     * com palavras-chave + bairro + data e hora do post.
     */
    public function prepararImagemParaPost(GmbPost $post): void
    {
        if (empty($post->imagem_url)) {
            return;
        }

        $tenant = $post->tenant;
        $perfil = $post->perfil;
        $dataHora = $post->data_agendada 
            ? $post->data_agendada->copy()->setTimezone('America/Sao_Paulo') 
            : now('America/Sao_Paulo');

        $urlStorage = Storage::disk('public')->url('');
        if (!str_starts_with($post->imagem_url, $urlStorage)) {
            return;
        }

        $caminhoRelativo = str_replace($urlStorage, '', $post->imagem_url);
        if (!Storage::disk('public')->exists($caminhoRelativo)) {
            return;
        }

        $extensao = pathinfo($caminhoRelativo, PATHINFO_EXTENSION) ?: 'jpg';
        $novoNome = $this->gerarNomeSeo($tenant, $perfil, $dataHora, $extensao, $post->titulo);
        $novaPasta = 'gmb-posts/' . $dataHora->format('Y/m');
        $novoCaminho = "{$novaPasta}/{$novoNome}";

        // Se já está com o nome exato esperado
        if ($caminhoRelativo === $novoCaminho) {
            return;
        }

        // Se a imagem de origem for da galeria (/galeria/), copia em vez de mover para manter o original na galeria
        if (str_contains($caminhoRelativo, 'galeria/')) {
            Storage::disk('public')->copy($caminhoRelativo, $novoCaminho);
        } else {
            Storage::disk('public')->move($caminhoRelativo, $novoCaminho);
        }

        $post->update([
            'imagem_url' => Storage::disk('public')->url($novoCaminho),
        ]);
    }
}
