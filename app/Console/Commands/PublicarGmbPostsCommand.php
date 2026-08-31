<?php

namespace App\Console\Commands;

use App\Models\GmbPost;
use App\Services\GmbPostPublishService;
use Illuminate\Console\Command;

class PublicarGmbPostsCommand extends Command
{
    protected $signature = 'gmb:publicar-posts';
    protected $description = 'Verifica e publica no Google Meu Negócio os posts agendados cujo horário já chegou';

    public function handle(GmbPostPublishService $service): int
    {
        $posts = GmbPost::withoutGlobalScopes()
            ->where('status', 'agendado')
            ->where('data_agendada', '<=', now())
            ->get();

        $total = $posts->count();

        if ($total === 0) {
            $this->info('Nenhum post agendado para publicação neste momento.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$total} post(s) para publicar no Google Meu Negócio.");

        $sucessos = 0;
        $falhas = 0;

        foreach ($posts as $post) {
            $this->line("Publicando Post #{$post->id} (Perfil: {$post->perfil?->nome})...");
            
            $ok = $service->publicar($post);

            if ($ok) {
                $sucessos++;
                $this->info(" -> Post #{$post->id} publicado com sucesso!");
            } else {
                $falhas++;
                $this->error(" -> Falha ao publicar Post #{$post->id}. Verifique logs.");
            }
        }

        $this->info("Resultado: {$sucessos} publicados, {$falhas} falhas.");
        return self::SUCCESS;
    }
}
