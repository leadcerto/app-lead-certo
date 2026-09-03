<?php

namespace App\Jobs;

use App\Models\Contato;
use App\Models\Mensagem;
use App\Models\MetaCampanhaGatilho;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\MetaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessarCommentToDmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $commentId,
        public string $postId,
        public string $textoComentario,
        public string $fromId,
        public ?string $fromName,
        public ?string $fromUsername,
        public string $plataforma, // 'instagram' ou 'facebook'
        public string $targetId    // page_id ou instagram_business_id
    ) {}

    public function handle(MetaService $metaService): void
    {
        // 1. Localiza a página ou conta do Instagram no banco para identificar o tenant
        $tenantId = null;
        $pageAccessToken = null;

        if ($this->plataforma === 'instagram') {
            $contaIg = MetaContaInstagram::withoutGlobalScopes()
                ->where('instagram_business_id', $this->targetId)
                ->with('pagina')
                ->first();

            if (! $contaIg) {
                Log::warning('ProcessarCommentToDmJob: conta do Instagram não encontrada', ['target_id' => $this->targetId]);
                return;
            }

            $tenantId = $contaIg->tenant_id;
            $pageAccessToken = $contaIg->pagina?->page_access_token;
        } else {
            $paginaFb = MetaPagina::withoutGlobalScopes()
                ->where('facebook_page_id', $this->targetId)
                ->first();

            if (! $paginaFb) {
                Log::warning('ProcessarCommentToDmJob: página do Facebook não encontrada', ['target_id' => $this->targetId]);
                return;
            }

            $tenantId = $paginaFb->tenant_id;
            $pageAccessToken = $paginaFb->page_access_token;
        }

        if (! $tenantId || ! $pageAccessToken) {
            return;
        }

        // 2. Busca regras de gatilho ativas para este tenant
        $campanhas = MetaCampanhaGatilho::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('ativo', true)
            ->where(function ($q) {
                $q->where('canal_alvo', $this->plataforma)
                  ->orWhere('canal_alvo', 'ambos');
            })
            ->get();

        $campanhaAtendida = null;

        foreach ($campanhas as $campanha) {
            // Se o gatilho for para um post específico, valida o ID
            if ($campanha->post_id_especifico && $campanha->post_id_especifico !== $this->postId) {
                continue;
            }

            if ($campanha->satisfazGatilho($this->textoComentario)) {
                $campanhaAtendida = $campanha;
                break;
            }
        }

        if (! $campanhaAtendida) {
            return; // Nenhuma regra bateu
        }

        Log::info('ProcessarCommentToDmJob: Gatilho acionado!', [
            'tenant_id'    => $tenantId,
            'campanha'     => $campanhaAtendida->nome,
            'from'         => $this->fromUsername ?: $this->fromName,
            'comentario'   => $this->textoComentario,
        ]);

        // 3. Resposta Pública no Comentário (opcional, configurada na campanha)
        if (! empty($campanhaAtendida->resposta_publica_comentario)) {
            $metaService->responderComentarioPublico(
                $pageAccessToken,
                $this->commentId,
                $campanhaAtendida->resposta_publica_comentario
            );
        }

        // 4. Abertura do Direct Privado (Private Replies API)
        $primeiroNome = $this->fromName ? explode(' ', trim($this->fromName))[0] : ($this->fromUsername ?: 'Cliente');
        $mensagemDirectPersonalizada = str_replace(
            ['{nome}', '{primeiro_nome}', '{username}'],
            [$this->fromName ?: $this->fromUsername, $primeiroNome, $this->fromUsername ?: ''],
            $campanhaAtendida->mensagem_direct
        );

        $enviouDirect = $metaService->enviarDirectPorComentario(
            $pageAccessToken,
            $this->commentId,
            $mensagemDirectPersonalizada
        );

        // 5. Criação do Contato & Ticket no Kanban
        $nomeFinal = $this->fromName ?: ($this->fromUsername ? "@{$this->fromUsername}" : 'Lead ' . ucfirst($this->plataforma));
        $origemTicket = $this->plataforma === 'instagram' ? 'instagram_direct' : 'facebook_messenger';

        // Tenta achar ou criar contato
        $contato = Contato::withoutGlobalScopes()
            ->where(function ($q) {
                if ($this->fromUsername) {
                    $q->where('nome', "@{$this->fromUsername}")
                      ->orWhere('observacoes', 'like', "%{$this->fromUsername}%");
                }
                $q->orWhere('observacoes', 'like', "%meta_user_id:{$this->fromId}%");
            })
            ->first();

        if (! $contato) {
            $contato = Contato::create([
                'nome'        => $nomeFinal,
                'observacoes' => "meta_user_id:{$this->fromId} | plataforma:{$this->plataforma} | @{$this->fromUsername}",
                'canal_origem'=> $origemTicket,
            ]);
        }

        VinculoContatoTenant::firstOrCreate([
            'tenant_id'  => $tenantId,
            'contato_id' => $contato->id,
        ]);

        // Cria Ticket no Kanban se não houver um aberto recentemente
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('contato_id', $contato->id)
            ->where('status', 'aberto')
            ->latest()
            ->first();

        if (! $ticket) {
            $ticket = TicketAtendimento::create([
                'tenant_id'     => $tenantId,
                'contato_id'    => $contato->id,
                'coluna_kanban' => 'novo_lead',
                'status'        => 'aberto',
                'aberto_em'     => now(),
                'origem'        => $origemTicket,
            ]);
        }

        // Registra a mensagem enviada no histórico
        if ($enviouDirect && $ticket) {
            Mensagem::create([
                'tenant_id' => $tenantId,
                'ticket_id' => $ticket->id,
                'remetente' => 'sistema',
                'tipo'      => 'texto',
                'conteudo'  => $mensagemDirectPersonalizada,
                'criado_em' => now(),
            ]);
        }
    }
}
