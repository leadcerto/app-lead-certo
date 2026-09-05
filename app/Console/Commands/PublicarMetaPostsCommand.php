<?php

namespace App\Console\Commands;

use App\Models\MetaPost;
use App\Services\MetaPostPublishService;
use Illuminate\Console\Command;

class PublicarMetaPostsCommand extends Command
{
    protected $signature = 'meta:publicar-posts';
    protected $description = 'Verifica e publica no Facebook/Instagram os posts agendados cujo horário já chegou';

    public function handle(MetaPostPublishService $service): int
    {
        $posts = MetaPost::withoutGlobalScopes()
            ->where('status', 'agendado')
            ->where('data_agendada', '<=', now())
            ->get();

        $total = $posts->count();

        if ($total === 0) {
            $this->info('Nenhum post agendado para publicação neste momento.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$total} post(s) para publicar no Facebook/Instagram.");

        $sucessos = 0;
        $falhas = 0;

        foreach ($posts as $post) {
            $this->line("Publicando Post #{$post->id} (canal: {$post->canal_alvo})...");

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
