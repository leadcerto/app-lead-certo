<?php

namespace App\Console\Commands;

use App\Models\GmbPost;
use App\Models\GmbPostImagem;
use App\Services\GmbImageSeoService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Achado real 2026-09-29 (Leonardo): posts do GMB com `imagem_url` fantasma
 * (arquivo nunca existiu de verdade no disco, ver GmbImageSeoService), alguns
 * já marcados como 'falha' pelo Google por causa disso. O fix em
 * prepararImagemParaPost()/imagemUrlValidaNoDisco() protege contra
 * recorrência, mas não repara os posts já quebrados — este comando faz a
 * reparação: reatribui uma foto que exista de verdade na galeria do mesmo
 * tenant e reabre pra publicação os posts cuja falha era só a imagem.
 */
class GmbRecuperarImagensQuebradasCommand extends Command
{
    protected $signature = 'gmb:recuperar-imagens-quebradas {--tenant= : Restringe a um tenant específico} {--dry-run : Só reporta o que faria, sem alterar nada}';
    protected $description = 'Reatribui uma imagem válida da galeria aos posts do GMB cuja imagem_url aponta pra um arquivo que não existe no disco';

    /** @var array<int, Collection<int, GmbPostImagem>> */
    private array $imagensValidasPorTenant = [];

    /** @var array<int, int> */
    private array $indicePorTenant = [];

    public function handle(GmbImageSeoService $seoService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = GmbPost::withoutGlobalScopes()->whereNotNull('imagem_url');
        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
        }

        $corrigidos = 0;
        $semImagemDisponivel = 0;
        $falhasPersistentes = 0;

        foreach ($query->get() as $post) {
            if ($seoService->imagemUrlValidaNoDisco($post->imagem_url)) {
                continue;
            }

            $imagemEscolhida = $this->proximaImagemValida($post->tenant_id, $seoService);
            if (!$imagemEscolhida) {
                $semImagemDisponivel++;
                $this->warn("Post #{$post->id} (tenant {$post->tenant_id}): nenhuma imagem válida na galeria pra substituir.");
                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] Post #{$post->id}: seria corrigido usando a imagem #{$imagemEscolhida->id} da galeria.");
                $corrigidos++;
                continue;
            }

            $post->update(['imagem_url' => $imagemEscolhida->imagem_url]);
            $seoService->prepararImagemParaPost($post);
            $post->refresh();

            if (!$seoService->imagemUrlValidaNoDisco($post->imagem_url)) {
                $falhasPersistentes++;
                $this->error("Post #{$post->id}: ainda sem imagem válida depois da tentativa.");
                continue;
            }

            if ($post->status === 'falha') {
                $post->update(['status' => 'agendado', 'log_erro' => null]);
            }

            $corrigidos++;
            $this->info("Post #{$post->id} corrigido.");
        }

        $this->info("Resumo: {$corrigidos} corrigido(s), {$semImagemDisponivel} sem imagem disponível, {$falhasPersistentes} falha(s) persistente(s).");

        return self::SUCCESS;
    }

    private function proximaImagemValida(int $tenantId, GmbImageSeoService $seoService): ?GmbPostImagem
    {
        if (!isset($this->imagensValidasPorTenant[$tenantId])) {
            $this->imagensValidasPorTenant[$tenantId] = GmbPostImagem::where('tenant_id', $tenantId)
                ->get()
                ->filter(fn (GmbPostImagem $img) => $seoService->imagemUrlValidaNoDisco($img->imagem_url))
                ->values();
            $this->indicePorTenant[$tenantId] = 0;
        }

        $disponiveis = $this->imagensValidasPorTenant[$tenantId];
        if ($disponiveis->isEmpty()) {
            return null;
        }

        $imagem = $disponiveis[$this->indicePorTenant[$tenantId] % $disponiveis->count()];
        $this->indicePorTenant[$tenantId]++;

        return $imagem;
    }
}
