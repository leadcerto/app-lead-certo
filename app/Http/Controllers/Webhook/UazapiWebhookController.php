<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\PushContatoParaGoogleJob;
use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Services\EcoTranscricaoService;
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
        $canal = \App\Models\WhatsappCanal::withoutGlobalScopes()->where('webhook_token', $webhookToken)->first();

        if (! $canal) {
            // Fallback transitório: tenants que ainda não têm o token migrado pro
            // registro em whatsapp_canais (lacuna no backfill do Task 3, ou algum
            // caso não coberto) continuam autenticando pelo token legado salvo em
            // tenants.uazapi_webhook_token. TODO(Task 15): remover este fallback
            // depois de confirmar em produção que não é mais exercitado.
            $tenantLegado = Tenant::where('uazapi_webhook_token', $webhookToken)->first();
            $canal = $tenantLegado
                ? \App\Models\WhatsappCanal::withoutGlobalScopes()
                    ->where('tenant_id', $tenantLegado->id)
                    ->where('provider', 'uazapi')
                    ->first()
                : null;

            if (! $canal) {
                Log::warning('Uazapi webhook: token inválido', ['token' => substr($webhookToken, 0, 8) . '...']);
                abort(401);
            }

            Log::warning('Uazapi webhook: canal resolvido via fallback legado (tenants.uazapi_webhook_token)', [
                'tenant' => $tenantLegado->id,
                'canal'  => $canal->id,
            ]);
        }

        $tenant = $canal->tenant;

        $payload = $request->all();

        $tipo = $payload['EventType'] ?? null;

        Log::debug('Uazapi webhook recebido', ['tenant' => $tenant->id, 'canal' => $canal->id, 'EventType' => $tipo]);

        match ($tipo) {
            'messages'   => $this->handleMensagem($payload, $tenant, $canal),
            'connection' => $this->handleConexao($payload, $canal),
            // Investigação em andamento (2026-07-30): não sabemos se a Uazapi manda
            // algum evento de histórico/backfill ao reconectar após queda de sessão
            // (ex: sincronização de mensagens perdidas, no estilo do WhatsApp Web).
            // Antes disso, o default era silencioso — se algum dia esse evento
            // chegou, nunca teríamos como saber. Loga em warning (nível já capturado
            // em produção) pra pegar a próxima ocorrência real com o payload
            // completo. Remover este log assim que o tipo de evento for identificado
            // e tratado de verdade (ou confirmado que não existe).
            default      => Log::warning('Uazapi webhook: EventType não tratado — payload completo abaixo', [
                'tenant_id' => $tenant->id,
                'canal_id'  => $canal->id,
                'EventType' => $tipo,
                'payload'   => $payload,
            ]),
        };

        return response()->json(['ok' => true]);
    }

    // -----------------------------------------------------------------
    // Mensagem recebida / enviada
    // -----------------------------------------------------------------

    private function handleMensagem(array $payload, Tenant $tenant, \App\Models\WhatsappCanal $canal): void
    {
        $msg = $payload['message'] ?? [];

        // WhatsApp manda mensagem de voz como mediaType 'ptt' (push-to-talk), não
        // 'audio' — normaliza aqui pra todo o resto do fluxo (que só verifica
        // 'audio') tratar do mesmo jeito. Sem isso, áudio de voz nunca virava
        // mensagem nenhuma (nem tipo, nem conteúdo, nem mídia).
        if (($msg['mediaType'] ?? null) === 'ptt') {
            $msg['mediaType'] = 'audio';
        }

        $fromMe   = $msg['fromMe'] ?? false;
        $isGroup  = $msg['isGroup'] ?? false;
        $chatId   = $msg['chatid'] ?? null; // ex: "5521997797960@s.whatsapp.net"
        $viaApi   = $msg['wasSentByApi'] ?? false;
        $messageId = $msg['messageid'] ?? null;

        if (! $chatId || $isGroup) {
            return;
        }

        // Uazapi reenvia o mesmo evento mais de uma vez em alguns casos (ex.: mídia,
        // onde o segundo envio traz metadados completos) — sem essa trava, a mesma
        // mensagem do lead vira duas linhas e o bot pode responder duas vezes ao mesmo
        // conteúdo, dessincronizando o card em relação à conversa real do WhatsApp.
        if ($messageId && Mensagem::withoutGlobalScopes()->where('provider_message_id', $messageId)->exists()) {
            Log::debug('Uazapi webhook: mensagem duplicada ignorada', ['messageid' => $messageId]);
            return;
        }

        // Número limpo e normalizado: "5521997797960"
        $telefone  = $this->normalizarTelefone(preg_replace('/@.+$/', '', $chatId));
        $conteudo  = $msg['text'] ?? null;
        $pushName  = $msg['senderName'] ?? null;
        $mediaType = $msg['mediaType'] ?? null; // 'image','audio','video','document' ou null

        // Uazapi manda um evento à parte com texto tipo "Album: 3 images" quando o
        // lead envia várias fotos juntas — é metadado da plataforma, não algo que
        // o lead escreveu. Ignora esse evento em si (as imagens chegam em eventos
        // separados, cada uma com seu próprio mediaType).
        if ($conteudo && ! $mediaType && preg_match('/^Album:\s*\d+\s*images?$/i', trim($conteudo))) {
            return;
        }

        // Loga payload completo de mídia para mapeamento
        if ($mediaType) {
            Log::debug('Uazapi media recebida', [
                'mediaType'   => $mediaType,
                'messageType' => $msg['messageType'] ?? null,
                'content'     => substr(json_encode($msg['content'] ?? null), 0, 2000),
                'fileUrl'     => $msg['fileUrl'] ?? ($msg['mediaUrl'] ?? ($msg['url'] ?? null)),
                'messageid'   => $msg['messageid'] ?? null,
            ]);
        }

        // Chamada WhatsApp perdida — messageType vem como 'call_log' ou contém 'call'
        $messageType = $msg['messageType'] ?? '';
        if (! $fromMe && str_contains(strtolower($messageType), 'call')) {
            $this->processarChamadaWhatsApp($tenant, $telefone, $pushName, $canal);
            return;
        }

        if ($fromMe) {
            // Franqueado respondeu pelo celular físico — passa para humano
            if (! $viaApi) {
                $this->transferirParaHumano($tenant, $telefone, $conteudo, $msg, $canal);
            }
            return;
        }

        // Mensagem recebida do lead
        $this->processarMensagemLead($tenant, $telefone, $conteudo, $pushName, $msg, $canal);
    }

    private function processarMensagemLead(Tenant $tenant, string $telefone, ?string $conteudo, ?string $pushName, array $msg, \App\Models\WhatsappCanal $canal): void
    {
        // Valida pushName — rejeita lixo como "~Deus", números, muito curto,
        // e (achado 2026-08-20) texto de propaganda/nome de negócio.
        $nomeExtracao = app(\App\Services\NomeExtracaoService::class);
        $nomeValido   = $nomeExtracao->pushNameValido($pushName) ? $nomeExtracao->formatarNome($pushName) : null;

        // Detecta origem a partir da mensagem (links rastreados com texto pré-preenchido)
        $origemDetectada = $this->detectarOrigem($conteudo);

        // Busca ou cria contato — usa nome validado se disponível
        $novoContato = false;
        $contato = $this->buscarOuCriarContato($telefone, ['nome' => $nomeValido ?: 'Sem Nome', 'origem' => $origemDetectada]);

        if ($contato->wasRecentlyCreated) {
            $novoContato = true;
        }

        // Atualiza nome se o contato ainda não tem nome real
        if ($nomeValido && $this->semNomeReal($contato)) {
            $contato->update(['nome' => $nomeValido]);
        }

        // Clique em botão interativo (buttonsResponseMessage) — trata antes do fluxo de texto normal
        $buttonId = $msg['buttonOrListid'] ?? null;

        if ($buttonId) {
            $ticketExistente = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('contato_id', $contato->id)
                ->whereIn('status', ['aberto', 'aguardando'])
                ->latest()
                ->first();

            if ($ticketExistente) {
                $executou = app(\App\Services\KanbanBotaoActionService::class)->executar($ticketExistente, $buttonId);

                if ($executou) {
                    return; // clique tratado — não cai no fluxo de texto normal
                }
            }
            // buttonId presente mas sem config correspondente (ou sem ticket aberto):
            // cai no fluxo normal abaixo, tratando a resposta como texto (fallback).
        }

        // Busca ticket aberto para este contato+tenant
        // Achado real (2026-08-12): duas mensagens do mesmo lead chegando quase
        // juntas geravam dois webhooks concorrentes — cada um checava "já tem
        // ticket aberto?" antes do outro terminar de gravar, e os dois criavam
        // ticket (e disparavam a sequência de boas-vindas) pro mesmo contato,
        // duplicando toda mensagem enviada ao lead (achado no canal Oficial,
        // mesma estrutura de código aqui — corrigido nos dois por precaução).
        // A trava garante que só uma requisição por vez resolve/cria o ticket.
        [$ticket, $ticketNovo] = \Illuminate\Support\Facades\Cache::lock("ticket-resolve:uazapi:{$tenant->id}:{$contato->id}", 10)
            ->block(5, function () use ($tenant, $contato, $canal, $conteudo, $origemDetectada) {
                $ticket = TicketAtendimento::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('contato_id', $contato->id)
                    ->whereIn('status', ['aberto', 'aguardando'])
                    ->latest()
                    ->first();

                $ticketNovo = false;
                if ($ticket) {
                    // Ticket já aberto recebeu mensagem por outro canal (número) — o ticket
                    // continua único por lead, mas o canal precisa refletir quem tocou por
                    // último, senão a resposta sai pelo número errado (mesmo padrão usado
                    // na reativação de ticket encerrado e em transferirParaHumano()).
                    if ($ticket->whatsapp_canal_id !== $canal->id) {
                        $ticket->update(['whatsapp_canal_id' => $canal->id]);
                    }
                } else {
                    // Verifica se há ticket encerrado: reativa para o Guardião classificar a mensagem
                    $reaberturaService = app(\App\Services\TicketReaberturaService::class);
                    $ticketEncerrado   = $reaberturaService->buscarTicketEncerrado($tenant->id, $contato->id);

                    if ($ticketEncerrado) {
                        // Nem toda mensagem pra um ticket encerrado deve reabrir o atendimento
                        // — uma despedida/agradecimento ("obrigado, já consegui") não precisa
                        // reabrir, mas informação útil de verdade precisa. A IA decide.
                        $reaberturaService->reabrirSeNecessario($ticketEncerrado, $canal->id, $conteudo);

                        $ticket = $ticketEncerrado;
                        // ticketNovo permanece false → se reativou, cai no elseif abaixo →
                        // SdrResponderJob; se não, agente_responsavel continua como estava
                        // (não 'bot'), então o elseif não dispara e nada é enviado ao lead.
                    } elseif ($contato->excluidoDoFunilComercial()) {
                        // Pedido do Leonardo (2026-08-28): contato marcado numa
                        // etiqueta do Google que não é "lead" não entra no
                        // funil comercial — não abre ticket novo pra ele.
                        return [null, false];
                    } else {
                        // Abre novo ticket
                        $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

                        $ticket = TicketAtendimento::create([
                            'tenant_id'          => $tenant->id,
                            'contato_id'         => $contato->id,
                            'whatsapp_canal_id'  => $canal->id,
                            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
                            'agente_responsavel' => 'bot',
                            'sdr_persona_id'     => $persona?->id,
                            'status'             => 'aberto',
                            'origem'             => $origemDetectada,
                            'aberto_em'          => now(),
                        ]);
                        $ticketNovo = true;
                    }
                }

                return [$ticket, $ticketNovo];
            });

        if (! $ticket) {
            return; // contato excluído do funil comercial — nada mais a processar
        }

        // Processa mídia se houver (imagem → visão IA / áudio → transcrição / etc)
        $mediaType = $msg['mediaType'] ?? null;
        $tipoMensagem = 'texto';
        $midiaUrl = null;
        if ($mediaType && $canal->tokenUazapi()) {
            try {
                $colunaConfig = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('coluna_kanban', $ticket->coluna_kanban)
                    ->first();
                $focoAnalise      = $mediaType === 'image' ? $colunaConfig?->foco_analise_imagem : null;
                $transcricaoAtiva = $colunaConfig?->transcricao_ativa ?? true;

                if ($mediaType === 'image') {
                    // Download único + 1 chamada de visão que já devolve descrição
                    // e itens juntos — antes disso a mesma imagem era baixada até
                    // 3x e passava por 2 chamadas de IA separadas, o que sob
                    // volume (2+ imagens seguidas) estourava timeout e deixava o
                    // card sem os itens mesmo com a descrição salva certinho
                    // (achado real 2026-08-15, ticket 3085, mesmo bug do Covercut).
                    $resultado    = app(MediaProcessorService::class)->processarImagemUnica($msg, $canal->tokenUazapi(), $focoAnalise, $transcricaoAtiva);
                    $conteudo     = $resultado['conteudo'];
                    $tipoMensagem = 'imagem';
                    $midiaUrl     = $resultado['midiaUrl'];

                    // Acumula os itens identificados na imagem no card, pra quem
                    // vende ver de relance o que já foi enviado sem reabrir cada foto.
                    if ($resultado['itens']) {
                        $listaAtual = $ticket->lista_itens ? $ticket->lista_itens . "\n" : '';
                        $ticket->update(['lista_itens' => $listaAtual . $resultado['itens']]);
                    }
                } else {
                    $processado = app(MediaProcessorService::class)->processar($msg, $canal->tokenUazapi(), null, $transcricaoAtiva);
                    if ($processado !== null) {
                        $conteudo     = $processado;
                        $tipoMensagem = match ($mediaType) {
                            'video' => 'video', 'audio' => 'audio', default => 'texto',
                        };
                        if (in_array($mediaType, ['audio', 'video'])) {
                            $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrl($msg, $canal->tokenUazapi(), $mediaType);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("MediaProcessorService falhou, continuando sem processar mídia", [
                    'mediaType' => $mediaType,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Achado real 2026-08-20 (Leonardo): NÃO ecoar a transcrição do áudio
        // do CLIENTE de volta pra ele mesmo — ele já sabe o que falou, isso
        // só existe pra o eco do lado do ATENDENTE (ver os outros pontos que
        // chamam EcoTranscricaoService com $ticket->nomePersonaDisplay()),
        // onde faz sentido porque o cliente pode não conseguir ouvir áudio.
        // A transcrição em si continua disponível pro sistema/IA normalmente
        // — ela já é salva como o próprio conteúdo da mensagem do lead
        // (`[Áudio transcrito: ...]`), esse eco era só uma duplicata enviada
        // de volta pro WhatsApp do cliente, sem função nenhuma.

        // Extração progressiva de nome a partir do conteúdo (texto ou transcrição de áudio)
        // Roda sempre que o contato ainda não tem nome real (usa telefone como nome)
        if ($conteudo && ($contato->nome === $contato->telefone || ! $contato->nome || ! $nomeValido)) {
            $nomeExtraido = app(\App\Services\NomeExtracaoService::class)->extrairDaMensagem($conteudo);
            if ($nomeExtraido) {
                $contato->update(['nome' => $nomeExtraido]);
                $contato->refresh();
                Log::info("Nome extraído do texto para contato #{$contato->id}: {$nomeExtraido}");
            }
        }

        // Item 11 do roteiro (2026-08-20): detecta o idioma do lead na
        // primeira mensagem substancial e traduz pro português (pro
        // atendente ler no Kanban) — só detecta 1x por ticket
        // (idioma_lead ainda null), não em toda mensagem, pra não gerar
        // custo de IA repetido depois que o idioma já está confirmado.
        // Só roda em texto/áudio (palavra do próprio lead) — nunca em
        // imagem/vídeo, cujo $conteudo é a DESCRIÇÃO gerada pela nossa
        // própria IA de visão (já em português, não é o lead "falando").
        // Também pula qualquer placeholder do sistema (todos começam com
        // "[", ex.: "[Áudio recebido — não foi possível transcrever]") —
        // não é texto real do lead pra detectar idioma.
        $idiomaMensagem = null;
        $conteudoPt     = null;
        if ($conteudo && in_array($tipoMensagem, ['texto', 'audio'], true)
            && ! str_starts_with(trim($conteudo), '[') && is_null($ticket->idioma_lead)) {
            $traducao       = app(\App\Services\TraducaoService::class);
            $idiomaDetectado = $traducao->detectarIdioma($conteudo);
            if ($idiomaDetectado) {
                $ticket->update(['idioma_lead' => $idiomaDetectado]);
                $idiomaMensagem = $idiomaDetectado;
                if ($idiomaDetectado !== 'pt') {
                    $conteudoPt = $traducao->traduzir($conteudo, 'pt', $idiomaDetectado);
                }
            }
        }

        // Salva a mensagem
        if ($conteudo) {
            Mensagem::create([
                'ticket_id'         => $ticket->id,
                'tenant_id'         => $tenant->id,
                'remetente'         => 'lead',
                'tipo'              => $tipoMensagem,
                'conteudo'          => $conteudo,
                'idioma'            => $idiomaMensagem,
                'conteudo_pt'       => $conteudoPt,
                'midia_url'         => $midiaUrl,
                'provider_message_id' => $msg['messageid'] ?? null,
                'enviado_em'        => now(),
            ]);
        }

        // Lead deu sinal de vida — zera o relógio dos estágios de silêncio
        // (conversas:followup), senão um novo período de silêncio retomaria
        // do estágio em que parou antes, em vez de recomeçar do zero.
        if ($ticket->followup_estagio_enviado !== 0) {
            $ticket->update(['followup_estagio_enviado' => 0]);
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
            $chaveEntrada = \App\Models\KanbanColuna::chaveDeEntrada($tenant->id);
            if ($ticket->coluna_kanban === $chaveEntrada && $conteudo) {
                // Lead respondeu à sequência → avança para a próxima coluna e dispara SDR
                $temMensagemBot = Mensagem::where('ticket_id', $ticket->id)
                    ->where('remetente', 'bot')
                    ->exists();
                $proximaColuna = \App\Models\KanbanColuna::proximaChave($tenant->id, $chaveEntrada);
                if ($temMensagemBot && $proximaColuna) {
                    $ticket->update(['coluna_kanban' => $proximaColuna]);
                    $ticket->coluna_kanban = $proximaColuna;
                    $delay = $this->sdrDelay($tenant->id, $proximaColuna);
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

    private function processarChamadaWhatsApp(Tenant $tenant, string $telefone, ?string $pushName, \App\Models\WhatsappCanal $canal): void
    {
        // Achado real 2026-08-20: esse caminho (chamada perdida) nunca
        // validava o pushName — ia direto pro banco sem passar por
        // validarPushName()/pushNameValido(), diferente do caminho de
        // mensagem de texto acima. Corrigido pra usar o mesmo validador.
        $nomeExtracao = app(\App\Services\NomeExtracaoService::class);
        $nomeValido   = $nomeExtracao->pushNameValido($pushName) ? $nomeExtracao->formatarNome($pushName) : null;

        // Ignora se já há ticket ativo (evita duplicar sequência)
        $contato = $this->buscarOuCriarContato($telefone, ['nome' => $nomeValido ?: 'Sem Nome', 'origem' => 'whatsapp']);

        if ($nomeValido && $this->semNomeReal($contato)) {
            $contato->update(['nome' => $nomeValido]);
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

        // Pedido do Leonardo (2026-08-28): contato marcado numa etiqueta do
        // Google que não é "lead" não entra no funil comercial.
        if ($contato->excluidoDoFunilComercial()) {
            return;
        }

        $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'whatsapp_canal_id'  => $canal->id,
            'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($tenant->id),
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
     * Busca um contato pelo telefone ou cria um novo — tolerante à corrida com o
     * job `contatos:sincronizar-google` (roda a cada 6h), que pode inserir o mesmo
     * telefone entre o SELECT e o INSERT do firstOrCreate normal. Sem essa proteção,
     * a exceção de chave duplicada derrubava a requisição inteira do webhook e a
     * mensagem do lead nunca chegava a ser salva.
     */
    private function buscarOuCriarContato(string $telefone, array $atributos): Contato
    {
        try {
            return Contato::firstOrCreate(['telefone' => $telefone], $atributos);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // O telefone pode pertencer a um contato apagado (soft delete) — a
            // restrição única do banco continua valendo mesmo apagado, então
            // firstOrCreate() bate de frente com ele sem nunca encontrá-lo
            // (a busca padrão ignora registros apagados). Sem isso, o webhook
            // ficava preso pra sempre nesse telefone.
            $contato = Contato::withTrashed()->where('telefone', $telefone)->first();
            if (! $contato) {
                throw $e;
            }
            if ($contato->trashed()) {
                $contato->restore();
            }
            return $contato;
        }
    }

    /**
     * Retorna true se o pushName parece um nome real.
     * Rejeita: começa com ~, só números, parece telefone, muito curto, emojis puros.
     */
    private function semNomeReal(\App\Models\Contato $c): bool
    {
        return $c->semNomeReal();
    }

    // A validação de pushName (antes um método privado duplicado aqui) foi
    // movida pra NomeExtracaoService::pushNameValido() em 2026-08-20, pra
    // ser compartilhada com o Covercut e com o caminho de chamada perdida
    // (que nunca validava nada antes).

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

    private function transferirParaHumano(Tenant $tenant, string $telefone, ?string $conteudo, array $msg, \App\Models\WhatsappCanal $canal): void
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

        // Mantém whatsapp_canal_id refletindo qual número tocou o ticket por último
        // (sem dividir tickets por canal — continua um ticket único por lead) e
        // muda responsável para humano se ainda estava com o bot.
        $updates = [];
        if ($ticket->whatsapp_canal_id !== $canal->id) {
            $updates['whatsapp_canal_id'] = $canal->id;
        }
        if ($ticket->agente_responsavel === 'bot') {
            $updates['agente_responsavel'] = 'humano';
            Log::info("Ticket #{$ticket->id} transferido para humano (resposta pelo celular)");
        }
        if ($updates) {
            $ticket->update($updates);
        }

        // Processa mídia (imagem/áudio/vídeo enviados pelo WhatsApp Web/celular) —
        // mesma lógica usada pra mensagens do lead, senão a mídia não aparece no card.
        $mediaType = $msg['mediaType'] ?? null;
        $tipoMensagem = 'texto';
        $midiaUrl = null;
        if ($mediaType && $canal->tokenUazapi() && in_array($mediaType, ['image', 'audio', 'video'])) {
            try {
                $midiaUrl     = app(MediaProcessorService::class)->baixarEPersistirUrl($msg, $canal->tokenUazapi(), $mediaType);
                $tipoMensagem = match ($mediaType) {
                    'image' => 'imagem', 'video' => 'video', default => 'audio',
                };

                if ($mediaType === 'audio') {
                    // Transcreve de verdade — antes só baixava a URL e usava o
                    // placeholder "[Áudio]" abaixo, sem passar pelo Whisper. Mesma
                    // cobertura que já existia pro áudio do lead.
                    $transcricaoAtiva = KanbanColunaConfig::withoutGlobalScopes()
                        ->where('tenant_id', $ticket->tenant_id)
                        ->where('coluna_kanban', $ticket->coluna_kanban)
                        ->value('transcricao_ativa') ?? true;

                    $processado = app(MediaProcessorService::class)->processar($msg, $canal->tokenUazapi(), null, $transcricaoAtiva);
                    if ($processado !== null) {
                        $conteudo = $processado;
                    }
                }

                if (! $conteudo) {
                    $conteudo = match ($mediaType) {
                        'image' => '[Imagem]', 'video' => '[Vídeo]', default => '[Áudio]',
                    };
                }
            } catch (\Throwable $e) {
                Log::warning('transferirParaHumano: falha ao processar mídia', [
                    'mediaType' => $mediaType, 'erro' => $e->getMessage(),
                ]);
            }
        }

        // Ecoa a transcrição do áudio de volta na própria conversa do WhatsApp,
        // igual ao áudio do lead — mesmo que o atendente tenha gravado pelo
        // WhatsApp Web/celular, quem estiver acompanhando a conversa pelo app
        // (sem abrir o painel) consegue ler sem precisar tocar o áudio.
        if ($mediaType === 'audio' && $tipoMensagem === 'audio') {
            $transcricaoBruta = app(MediaProcessorService::class)->extrairTranscricaoBruta($conteudo);
            if ($transcricaoBruta) {
                app(EcoTranscricaoService::class)->enviar($canal, $ticket, $telefone, $transcricaoBruta, $ticket->nomePersonaDisplay());
            }
        }

        // Sem mediaType reconhecido e sem texto: caso confirmado em produção
        // (2026-08-03, ticket #2709) de uma mensagem de orçamento com preview de
        // imagem carregada de link (extendedTextMessage) que nunca apareceu no
        // card — a Uazapi aparentemente não popula `msg.text` pra esse tipo.
        // Loga o payload bruto pra mapear o formato exato na próxima ocorrência.
        //
        // Não cria mensagem-placeholder pra QUALQUER evento vazio, porque reação de
        // emoji, edição e exclusão de mensagem no WhatsApp também chegam como
        // `fromMe` sem mediaType/texto — um placeholder ali viraria spam no card a
        // cada 👍 do vendedor. Só cria placeholder quando o messageType não bate
        // com um desses tipos "sem conteúdo por design" conhecidos.
        if (! $conteudo && ! $mediaType) {
            Log::warning('transferirParaHumano: mensagem fromMe sem mediaType e sem texto reconhecido — payload completo abaixo', [
                'tenant_id' => $tenant->id,
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'msg'       => $msg,
            ]);

            $messageType = mb_strtolower($msg['messageType'] ?? '');
            $semConteudoPorDesign = preg_match('/reaction|protocol|revoke|delete|receipt|presence|pin|poll|star/', $messageType);

            if (! $semConteudoPorDesign) {
                $conteudo = '[Mensagem sem conteúdo reconhecido]';
            }
        }

        // Salva a mensagem enviada pelo franqueado
        if ($conteudo) {
            Mensagem::create([
                'ticket_id'         => $ticket->id,
                'tenant_id'         => $tenant->id,
                'remetente'         => 'humano',
                'tipo'              => $tipoMensagem,
                'conteudo'          => $conteudo,
                'midia_url'         => $midiaUrl,
                'provider_message_id' => $msg['messageid'] ?? null,
                'enviado_em'        => now(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Atualização de conexão
    // -----------------------------------------------------------------

    private function handleConexao(array $payload, \App\Models\WhatsappCanal $canal): void
    {
        $status = $payload['data']['status'] ?? null;

        if ($status === 'open') {
            $canal->update([
                'status'          => 'connected',
                'connected_since' => now(),
            ]);
        } elseif (in_array($status, ['close', 'connecting', 'timeout'])) {
            $canal->update(['status' => 'disconnected']);
            Log::warning("Canal #{$canal->id} WhatsApp desconectado", ['status' => $status]);
        }
    }
}
