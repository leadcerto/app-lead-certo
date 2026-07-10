<?php

namespace App\Jobs;

use App\Models\Mensagem;
use App\Models\SpintaxVariavel;
use App\Models\TicketAtendimento;
use App\Services\HumanizacaoService;
use App\Services\UazapiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SequenciaMensagemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int     $ticketId,
        public string  $conteudo,
        public ?string $imagemUrl = null,
        public ?string $colunaKanban = null,
        public bool    $enviarBotoes = false,
    ) {}

    public function handle(HumanizacaoService $humanizacao, UazapiService $uazapi): void
    {
        $ticket = TicketAtendimento::with(['contato', 'tenant'])->find($this->ticketId);

        if (! $ticket || $ticket->coluna_kanban === 'encerrado') {
            return;
        }

        $bloqueado = \App\Models\VinculoContatoTenant::where('contato_id', $ticket->contato_id)
            ->where('tenant_id', $ticket->tenant_id)
            ->whereNotNull('bloqueado_em')
            ->exists();

        if ($bloqueado) {
            Log::info('SequenciaMensagemJob: contato bloqueado (opt-out) neste tenant, envio cancelado', [
                'ticket_id' => $this->ticketId,
            ]);
            return;
        }

        // Se a sequência foi vinculada a uma coluna específica, cancelar se o lead saiu dela.
        // Acesso via ?? por segurança: jobs enfileirados antes desta propriedade existir
        // não têm colunaKanban no payload serializado, e o unserialize não roda o construtor
        // (então o default do parâmetro nunca é aplicado nesses jobs antigos).
        $colunaKanban = $this->colunaKanban ?? null;
        if ($colunaKanban && $ticket->coluna_kanban !== $colunaKanban) {
            return;
        }

        $telefone = $ticket->contato?->telefone;
        $tenant   = $ticket->tenant;

        if (! $telefone || ! $tenant?->uazapi_instance_token) {
            Log::warning('SequenciaMensagemJob: sem telefone ou token', ['ticket_id' => $this->ticketId]);
            return;
        }

        $token = $tenant->uazapi_instance_token;

        // Resolve todas as variáveis
        $nomeContato = $ticket->contato?->nome;
        $temNome     = $nomeContato && $nomeContato !== $telefone;
        $now         = now()->timezone('America/Sao_Paulo');

        $vars = [
            '{nome}'             => $temNome ? $nomeContato : '',
            '{empresa}'          => $tenant->nome ?? '',
            '{endereco_saida}'   => $ticket->endereco_saida ?? '',
            '{endereco_destino}' => $ticket->endereco_destino ?? '',
            '{data_hoje}'        => $now->locale('pt_BR')->isoFormat('D [de] MMMM'),
            '{dia_semana}'       => $now->locale('pt_BR')->isoFormat('dddd'),
            '{saudacao_tempo}'   => $this->getSaudacao($now),
            '{referencia_dia}'   => $this->getReferenciaHoje($now),
            '{tempo_passado}'    => $this->getTempoPassado($ticket),
        ];

        // Variáveis de sorteio: defaults + customizadas do tenant
        foreach (SpintaxVariavel::getTodasParaTenant($ticket->tenant_id) as $nome => $opcoes) {
            if (! empty($opcoes)) {
                $vars["{{$nome}}"] = $opcoes[array_rand($opcoes)];
            }
        }

        $texto = $this->conteudo !== ''
            ? str_replace(array_keys($vars), array_values($vars), $this->conteudo)
            : '';

        if (! $temNome) {
            $texto = preg_replace('/\{nome\},?\s*/u', '', $texto);
        }

        if ($this->imagemUrl) {

            $imagemOk = $uazapi->enviarImagem($token, $telefone, $this->imagemUrl, $texto);

            if ($imagemOk) {
                Mensagem::create([
                    'ticket_id'  => $ticket->id,
                    'tenant_id'  => $ticket->tenant_id,
                    'remetente'  => 'bot',
                    'tipo'       => 'imagem',
                    'conteudo'   => $texto ?: '[Imagem]',
                    'enviado_em' => now(),
                ]);
            } else {
                // Fallback: API de mídia indisponível — envia só o texto (ou URL pública)
                $fallback = $texto ?: $this->imagemUrl;
                $humanizacao->processar($token, $telefone, $fallback);

                Mensagem::create([
                    'ticket_id'  => $ticket->id,
                    'tenant_id'  => $ticket->tenant_id,
                    'remetente'  => 'bot',
                    'tipo'       => 'texto',
                    'conteudo'   => $fallback,
                    'enviado_em' => now(),
                ]);

                Log::warning('SequenciaMensagemJob: enviarImagem falhou, enviado fallback de texto', [
                    'ticket_id' => $this->ticketId,
                    'imagem_url' => $this->imagemUrl,
                ]);
            }
        } else {
            // Só texto — com humanização completa
            $humanizacao->processar($token, $telefone, $texto);

            Mensagem::create([
                'ticket_id'  => $ticket->id,
                'tenant_id'  => $ticket->tenant_id,
                'remetente'  => 'bot',
                'tipo'       => 'texto',
                'conteudo'   => $texto,
                'enviado_em' => now(),
            ]);
        }

        // Igual ao acesso via ?? já usado pra colunaKanban: jobs serializados antes
        // desta propriedade existir não têm enviarBotoes no payload, e o unserialize
        // não roda o construtor.
        if ($this->enviarBotoes ?? false) {
            app(\App\Services\KanbanBotaoActionService::class)->enviarBotoesDaColuna($ticket);
        }
    }

    private function getSaudacao(\Illuminate\Support\Carbon $now): string
    {
        $hora = $now->hour;
        if ($hora < 12) return 'Bom dia';
        if ($hora < 18) return 'Boa tarde';
        return 'Boa noite';
    }

    private function getReferenciaHoje(\Illuminate\Support\Carbon $now): string
    {
        $diaSemana = $now->dayOfWeek;
        $hora      = $now->hour;
        $dia       = $now->locale('pt_BR')->isoFormat('dddd');

        if ($diaSemana === 5 && $hora >= 14) return 'pro final de semana';
        if ($diaSemana === 6) return 'neste sábado';
        if ($diaSemana === 0) return 'neste domingo';
        if ($hora >= 17) return 'ainda hoje';
        return "nesta {$dia}";
    }

    private function getTempoPassado(TicketAtendimento $ticket): string
    {
        $ultimaMensagemBot = Mensagem::withoutGlobalScopes()
            ->where('ticket_id', $ticket->id)
            ->where('remetente', 'bot')
            ->orderByDesc('enviado_em')
            ->value('enviado_em');

        if (! $ultimaMensagemBot) return 'recentemente';

        $diffMin = now()->diffInMinutes($ultimaMensagemBot);
        if ($diffMin < 60) return 'mais cedo';
        $diffH = (int) ($diffMin / 60);
        if ($diffH < 24) return $diffH === 1 ? 'há uma hora' : "há {$diffH} horas";
        $diffD = now()->diffInDays($ultimaMensagemBot);
        if ($diffD === 1) return 'ontem';
        if ($diffD < 7) return "há {$diffD} dias";
        if ($diffD < 14) return 'na semana passada';
        return 'há algumas semanas';
    }
}
