<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\PushContatoParaGoogleJob;
use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\MediaProcessorService;
use App\Services\SequenciaService;
use App\Services\TelefoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UazapiWebhookController extends Controller
{
    public function handle(Request $request, string $webhookToken): JsonResponse
    {
        // Autentica pelo token opaco na URL — lookup por coluna unique
        $tenant = Tenant::where('uazapi_webhook_token', $webhookToken)->first();

        if (! $tenant) {
            Log::warning('Uazapi webhook: token inválido', ['token' => substr($webhookToken, 0, 8) . '...']);
            abort(401);
        }

        $payload = $request->all();

        $tipo = $payload['EventType'] ?? null;

        Log::debug('Uazapi webhook recebido', ['tenant' => $tenant->id, 'EventType' => $tipo]);

        match ($tipo) {
            'messages'   => $this->handleMensagem($payload, $tenant),
            'connection' => $this->handleConexao($payload, $tenant),
            default      => null,
        };

        return response()->json(['ok' => true]);
    }

    // -----------------------------------------------------------------
    // Mensagem recebida / enviada
    // -----------------------------------------------------------------

    private function handleMensagem(array $payload, Tenant $tenant): void
    {
        $msg = $payload['message'] ?? [];

        $fromMe   = $msg['fromMe'] ?? false;
        $isGroup  = $msg['isGroup'] ?? false;
        $chatId   = $msg['chatid'] ?? null; // ex: "5521997797960@s.whatsapp.net"
        $viaApi   = $msg['wasSentByApi'] ?? false;

        if (! $chatId || $isGroup) {
            return;
        }

        // Número limpo e normalizado: "5521997797960"
        $telefone  = $this->normalizarTelefone(preg_replace('/@.+$/', '', $chatId));
        $conteudo  = $msg['text'] ?? null;
        $pushName  = $msg['senderName'] ?? null;
        $mediaType = $msg['mediaType'] ?? null; // 'image','audio','video','document' ou null

        // Loga payload completo de mídia para mapeamento
        if ($mediaType) {
            Log::debug('Uazapi media recebida', [
                'mediaType'   => $mediaType,
                'messageType' => $msg['messageType'] ?? null,
                'content'     => substr(json_encode($msg['content'] ?? null), 0, 300),
                'fileUrl'     => $msg['fileUrl'] ?? ($msg['mediaUrl'] ?? ($msg['url'] ?? null)),
                'messageid'   => $msg['messageid'] ?? null,
            ]);
        }

        // Chamada WhatsApp perdida — messageType vem como 'call_log' ou contém 'call'
        $messageType = $msg['messageType'] ?? '';
        if (! $fromMe && str_contains(strtolower($messageType), 'call')) {
            $this->processarChamadaWhatsApp($tenant, $telefone, $pushName);
            return;
        }

        if ($fromMe) {
            // Franqueado respondeu pelo celular físico — passa para humano
            if (! $viaApi) {
                $this->transferirParaHumano($tenant, $telefone, $conteudo);
            }
            return;
        }

        // Mensagem recebida do lead
        $this->processarMensagemLead($tenant, $telefone, $conteudo, $pushName, $msg, $tenant->uazapi_instance_token);
    }

    private function processarMensagemLead(Tenant $tenant, string $telefone, ?string $conteudo, ?string $pushName, array $msg = [], string $instanceToken = ''): void
    {
        // Valida pushName — rejeita lixo como "~Deus", números, muito curto
        $nomeValido = $this->validarPushName($pushName) ? $pushName : null;

        // Detecta origem a partir da mensagem (links rastreados com texto pré-preenchido)
        $origemDetectada = $this->detectarOrigem($conteudo);

        // Busca ou cria contato — usa nome validado se disponível
        $novoContato = false;
        $contato = Contato::firstOrCreate(
            ['telefone' => $telefone],
            ['nome' => $nomeValido ?: 'Sem Nome', 'origem' => $origemDetectada]
        );

        if ($contato->wasRecentlyCreated) {
            $novoContato = true;
        }

        // Atualiza nome se o contato ainda não tem nome real
        if ($nomeValido && $this->semNomeReal($contato)) {
            $contato->update(['nome' => $nomeValido]);
        }

        // Busca ticket aberto para este contato+tenant
        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        $ticketNovo = false;
        if (! $ticket) {
            // Verifica se há ticket encerrado: reativa para o Guardião classificar a mensagem
            $ticketEncerrado = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('contato_id', $contato->id)
                ->where('coluna_kanban', 'encerrado')
                ->latest()
                ->first();

            if ($ticketEncerrado) {
                $ticketEncerrado->update([
                    'status'             => 'aberto',
                    'agente_responsavel' => 'bot',
                ]);
                $ticket = $ticketEncerrado;
                // ticketNovo permanece false → cai no elseif abaixo → SdrResponderJob na coluna encerrado
                Log::info("Webhook: ticket #{$ticketEncerrado->id} reativado para Guardião (mensagem pós-encerramento)");
            } else {
                // Abre novo ticket
                $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

                $ticket = TicketAtendimento::create([
                    'tenant_id'          => $tenant->id,
                    'contato_id'         => $contato->id,
                    'coluna_kanban'      => 'lead_novo',
                    'agente_responsavel' => 'bot',
                    'sdr_persona_id'     => $persona?->id,
                    'status'             => 'aberto',
                    'origem'             => $origemDetectada,
                    'aberto_em'          => now(),
                ]);
                $ticketNovo = true;
            }
        }

        // Processa mídia se houver (imagem → visão IA / áudio → transcrição / etc)
        $mediaType = $msg['mediaType'] ?? null;
        $tipoMensagem = 'texto';
        if ($mediaType && $instanceToken) {
            try {
                $processado = app(MediaProcessorService::class)->processar($msg, $instanceToken);
                if ($processado !== null) {
                    $conteudo     = $processado;
                    $tipoMensagem = in_array($mediaType, ['image','video']) ? 'imagem' : ($mediaType === 'audio' ? 'audio' : 'texto');
                }
            } catch (\Throwable $e) {
                Log::warning("MediaProcessorService falhou, continuando sem processar mídia", [
                    'mediaType' => $mediaType,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Extração progressiva de nome a partir do conteúdo (texto ou transcrição de áudio)
        // Roda sempre que o contato ainda não tem nome real (usa telefone como nome)
        if ($conteudo && ($contato->nome === $contato->telefone || ! $contato->nome || ! $nomeValido)) {
            $nomeExtraido = $this->extrairNomeDaTexto($conteudo);
            if ($nomeExtraido) {
                $contato->update(['nome' => $nomeExtraido]);
                $contato->refresh();
                Log::info("Nome extraído do texto para contato #{$contato->id}: {$nomeExtraido}");
            }
        }

        // Salva a mensagem
        if ($conteudo) {
            Mensagem::create([
                'ticket_id'  => $ticket->id,
                'tenant_id'  => $tenant->id,
                'remetente'  => 'lead',
                'tipo'       => $tipoMensagem,
                'conteudo'   => $conteudo,
                'enviado_em' => now(),
            ]);
        }

        // Garante vínculo contato↔tenant e envia pro Google se for contato novo
        $vinculo = VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ]);

        if ($novoContato || ! $vinculo->google_resource_name) {
            dispatch(new PushContatoParaGoogleJob($contato->id, $tenant->id, $nomeValido ?? $pushName));
        }

        // Novo ticket: dispara sequência automática
        if ($ticketNovo) {
            app(SequenciaService::class)->iniciarParaTicket($ticket);
        } else {
            // Lead respondeu em ticket existente
            if ($ticket->coluna_kanban === 'lead_novo' && $conteudo) {
                // Lead respondeu à sequência → avança para em_atendimento e dispara SDR
                $temMensagemBot = Mensagem::where('ticket_id', $ticket->id)
                    ->where('remetente', 'bot')
                    ->exists();
                if ($temMensagemBot) {
                    $ticket->update(['coluna_kanban' => 'em_atendimento']);
                    $ticket->coluna_kanban = 'em_atendimento';
                    $delay = $this->sdrDelay($tenant->id, 'em_atendimento');
                    dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, $delay))
                        ->delay(now()->addSeconds($delay));
                }
            } elseif ($ticket->agente_responsavel === 'bot' && $conteudo) {
                $delay = $this->sdrDelay($tenant->id, $ticket->coluna_kanban);
                dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, $delay))
                    ->delay(now()->addSeconds($delay));
            }
        }
    }

    private function processarChamadaWhatsApp(Tenant $tenant, string $telefone, ?string $pushName): void
    {
        // Ignora se já há ticket ativo (evita duplicar sequência)
        $contato = Contato::firstOrCreate(
            ['telefone' => $telefone],
            ['nome' => $pushName ?: 'Sem Nome', 'origem' => 'whatsapp']
        );

        if ($pushName && $this->semNomeReal($contato)) {
            $contato->update(['nome' => $pushName]);
        }

        VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ]);

        $ticketExistente = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        if ($ticketExistente) {
            Log::info('Secretária WhatsApp: chamada ignorada — ticket já aberto', [
                'tenant'  => $tenant->id,
                'telefone' => $telefone,
                'ticket'  => $ticketExistente->id,
            ]);
            return;
        }

        $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => 'lead_novo',
            'agente_responsavel' => 'bot',
            'sdr_persona_id'     => $persona?->id,
            'status'             => 'aberto',
            'origem'             => 'ligacao',
            'aberto_em'          => now(),
        ]);

        Log::info('Secretária WhatsApp: chamada perdida — iniciando sequência', [
            'tenant'   => $tenant->id,
            'telefone' => $telefone,
            'ticket'   => $ticket->id,
        ]);

        app(SequenciaService::class)->iniciarParaTicket($ticket);
    }

    /**
     * Retorna true se o pushName parece um nome real.
     * Rejeita: começa com ~, só números, parece telefone, muito curto, emojis puros.
     */
    private function semNomeReal(\App\Models\Contato $c): bool
    {
        $nome = trim($c->nome ?? '');
        return ! $nome || $nome === $c->telefone || strtolower($nome) === 'sem nome';
    }

    private function validarPushName(?string $nome): bool
    {
        if (! $nome || mb_strlen(trim($nome)) < 2) return false;

        $nome = trim($nome);

        // WhatsApp coloca ~ antes de nomes de status — não é nome real
        if (str_starts_with($nome, '~')) return false;

        // Só dígitos ou formatação de telefone
        $soNumeros = preg_replace('/[\s\-\+\(\)\.]+/', '', $nome);
        if (ctype_digit($soNumeros) && strlen($soNumeros) >= 8) return false;

        // Muito curto (1 char ou só espaços)
        if (mb_strlen(preg_replace('/\s+/', '', $nome)) < 2) return false;

        // Só emojis / caracteres não-alfabéticos
        if (! preg_match('/\p{L}/u', $nome)) return false;

        return true;
    }

    /**
     * Tenta extrair o primeiro nome mencionado em uma mensagem de texto ou transcrição.
     * Cobre padrões comuns em português como "meu nome é X", "aqui é X", "sou X", etc.
     * Retorna null se nenhum padrão for encontrado.
     */
    private function extrairNomeDaTexto(string $texto): ?string
    {
        $texto = strip_tags($texto);

        // Padrões em português — sem flag /i nos character classes de nome para exigir capitalização real
        $padroes = [
            // "meu nome é X", "me chamo X", "aqui é X", "aqui fala X"
            '/(?:meu nome é|me chamo|pode me chamar de|aqui é|aqui fala|falando aqui|aqui[,\s]+(?:é\s)?(?:o|a)\s)\s*([A-ZÀ-Ú][a-zà-ú]+(?:\s[A-ZÀ-Ú][a-zà-ú]+)?)/iu',
            // "Oi, João" / "Olá, Maria" — sem /i: exige maiúscula real no nome capturado
            '/^(?:oi|olá|boa\s\w+)[,\s!]+([A-ZÀ-Ú][a-zà-ú]{2,}(?:\s[A-ZÀ-Ú][a-zà-ú]+)?)\s/u',
            // "sou o João" / "sou a Maria"
            '/\bsou\s+(?:o|a)?\s*([A-ZÀ-Ú][a-zà-ú]{2,}(?:\s[A-ZÀ-Ú][a-zà-ú]+)?)/u',
            // "chamo X" / "me chamo X"
            '/(?:me\s+)?chamo\s+([A-ZÀ-Ú][a-zà-ú]{2,}(?:\s[A-ZÀ-Ú][a-zà-ú]+)?)/u',
        ];

        // Verbos, saudações e termos de negócio que NUNCA são primeiros nomes
        $naoNomes = [
            'frete', 'mudança', 'mudanças', 'orçamento', 'aqui', 'favor',
            'boa', 'bom', 'tarde', 'manhã', 'noite', 'dia', 'tudo',
            'estou', 'preciso', 'precisando', 'quero', 'querendo',
            'gostaria', 'gostando', 'tenho', 'tendo',
            'vim', 'venho', 'venha', 'busco', 'buscando',
            'solicito', 'solicitando', 'peço', 'pedindo', 'necessito',
            'seria', 'posso', 'poderia', 'podendo',
            'olá', 'oi', 'sim', 'não', 'ok',
        ];

        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $texto, $m)) {
                $nome = trim($m[1]);
                $primeiraWord = mb_strtolower(explode(' ', $nome)[0], 'UTF-8');
                if (in_array($primeiraWord, $naoNomes) || in_array(mb_strtolower($nome, 'UTF-8'), $naoNomes)) {
                    continue;
                }
                return mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return null;
    }

    /**
     * Detecta a origem do lead a partir do texto da primeira mensagem.
     * Funciona com links rastreados: wa.me/...?text=Vim+pelo+Instagram
     * Retorna o canal identificado ou 'whatsapp' como padrão.
     */
    private function sdrDelay(int $tenantId, string $coluna): int
    {
        $config = KanbanColunaConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('coluna_kanban', $coluna)
            ->value('sdr_delay_segundos');

        return $config ?? SdrResponderJob::DEBOUNCE_SEGUNDOS;
    }

    private function normalizarTelefone(string $telefone): string
    {
        $normalizado = app(TelefoneService::class)->normalizar($telefone);

        if ($normalizado) {
            return $normalizado;
        }

        // Fallback: remove não-dígitos e adiciona 55 se necessário
        $digits = preg_replace('/\D/', '', $telefone);
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = '55' . $digits;
        }
        Log::warning('Webhook: telefone não normalizável', ['raw' => $telefone, 'fallback' => $digits]);
        return $digits;
    }

    private function detectarOrigem(?string $mensagem): string
    {
        if (! $mensagem) return 'whatsapp';

        $texto = mb_strtolower(strip_tags($mensagem));

        // Ordem importa: mais específico primeiro
        $mapa = [
            'google_ads'  => ['google ads', 'anuncio google', 'anúncio google', 'ads google'],
            'google'      => ['google', 'pesquisa google', 'busca google'],
            'instagram'   => ['instagram', 'insta'],
            'facebook'    => ['facebook'],
            'tiktok'      => ['tiktok', 'tik tok'],
            'youtube'     => ['youtube'],
            // Padrão do botão do blog: "Olá, gostaria de um orçamento de Frete em [bairro]"
            'blog'        => ['blog', 'gostaria de um orçamento de frete', 'gostaria de um orcamento de frete', 'orçamento de frete em', 'orcamento de frete em'],
            'indicacao'   => ['indicacao', 'indicação', 'indicado', 'indicada', 'me indicaram', 'me indicou', 'por indicacao', 'por indicação'],
            'site'        => ['pelo site', 'no site', 'seu site', 'o site'],
        ];

        foreach ($mapa as $origem => $termos) {
            foreach ($termos as $termo) {
                if (str_contains($texto, $termo)) {
                    return $origem;
                }
            }
        }

        return 'whatsapp';
    }

    private function transferirParaHumano(Tenant $tenant, string $telefone, ?string $conteudo): void
    {
        $contato = Contato::where('telefone', $telefone)->first();
        if (! $contato) {
            return;
        }

        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('status', ['aberto', 'aguardando'])
            ->latest()
            ->first();

        if (! $ticket) {
            return;
        }

        // Muda responsável para humano se ainda estava com o bot
        if ($ticket->agente_responsavel === 'bot') {
            $ticket->update(['agente_responsavel' => 'humano']);
            Log::info("Ticket #{$ticket->id} transferido para humano (resposta pelo celular)");
        }

        // Salva a mensagem enviada pelo franqueado
        if ($conteudo) {
            Mensagem::create([
                'ticket_id'  => $ticket->id,
                'tenant_id'  => $tenant->id,
                'remetente'  => 'humano',
                'tipo'       => 'texto',
                'conteudo'   => $conteudo,
                'enviado_em' => now(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Atualização de conexão
    // -----------------------------------------------------------------

    private function handleConexao(array $payload, Tenant $tenant): void
    {
        $status = $payload['data']['status'] ?? null;

        if ($status === 'open') {
            $tenant->update([
                'whatsapp_status'          => 'connected',
                'whatsapp_connected_since' => now(),
            ]);
        } elseif (in_array($status, ['close', 'connecting', 'timeout'])) {
            $tenant->update(['whatsapp_status' => 'disconnected']);
            Log::warning("Tenant #{$tenant->id} WhatsApp desconectado", ['status' => $status]);
        }
    }
}
