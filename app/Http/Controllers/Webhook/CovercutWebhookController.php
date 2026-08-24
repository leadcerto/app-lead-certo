<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\SdrResponderJob;
use App\Models\Contato;
use App\Models\KanbanColuna;
use App\Models\KanbanColunaConfig;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use App\Models\WhatsappCanal;
use App\Services\EcoTranscricaoService;
use App\Services\MediaProcessorService;
use App\Services\SequenciaService;
use App\Services\TelefoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do canal oficial (Covercut/Meta Cloud API). Processa texto, áudio
 * (transcrição) e imagem (descrição + itens identificados) — ver
 * docs/superpowers/specs/2026-07-30-midia-canal-oficial-covercut-design.md.
 * Vídeo/documento têm placeholder sem análise real (paridade com o Uazapi).
 * Também processa mensagens enviadas pelo atendente direto pelo WhatsApp
 * Business App (modo Coexistence — `event: echo`, `direction: outbound`,
 * `echo_source: phone`), equivalente ao `fromMe && !viaApi` do Uazapi — ver
 * UazapiWebhookController::transferirParaHumano(). Echo com
 * `echo_source: api` é ignorado (mensagem já foi salva quando nós mesmos
 * chamamos a API de envio).
 * Sem botão nem chamada de voz (fora de escopo). Deliberadamente autocontido
 * (não reusa UazapiWebhookController) — ver Architecture no plano original
 * (2026-07-29).
 */
class CovercutWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // A Covercut identifica nosso número de destino em `from_number_id` (campo
        // top-level do payload real) — `to`/`phone_number_id` ficam como fallback
        // tolerante, mas não são o formato documentado.
        $phoneNumberId = $payload['from_number_id'] ?? $payload['to'] ?? $payload['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning('Covercut webhook: payload sem from_number_id/to/phone_number_id identificável');
            abort(400);
        }

        $canal = WhatsappCanal::withoutGlobalScopes()
            ->where('provider', 'covercut')
            ->whereJsonContains('config->phone_number_id', $phoneNumberId)
            ->first();

        if (! $canal) {
            // 401 (não 404): não expor via enumeração se um phone_number_id existe ou
            // não — mesmo status de assinatura inválida, ver validarAssinatura() abaixo.
            Log::warning('Covercut webhook: nenhum canal encontrado para phone_number_id', ['phone_number_id' => $phoneNumberId]);
            abort(401);
        }

        $assinaturaValida = $this->validarAssinatura($request, $canal);
        if (! $assinaturaValida) {
            Log::warning('Covercut webhook: assinatura inválida', ['canal_id' => $canal->id]);
            abort(401);
        }

        $event     = $payload['event'] ?? null;
        $direction = $payload['direction'] ?? null;

        if ($event === 'message' && $direction === 'inbound') {
            $this->processarMensagem($payload, $canal);
        } elseif ($event === 'echo' && $direction === 'outbound' && ($payload['echo_source'] ?? null) === 'phone') {
            $this->processarMensagemHumana($payload, $canal);
        } elseif ($event === 'echo' && ($payload['echo_source'] ?? null) === 'api') {
            // Eco da nossa própria mensagem enviada via API — nada a fazer, já
            // registramos a Mensagem no momento do envio. Não é um evento "não
            // tratado" de verdade, só não precisa de ação nenhuma.
        } else {
            // Achado real 2026-08-14: uma ligação perdida com número sem WhatsApp
            // teve a mensagem de abertura "aceita" pela API (HTTP sucesso), mas
            // nunca chegou de verdade — a Meta só informaria isso depois, via um
            // evento de status de entrega (`event: "status"`, provavelmente com
            // `status.status: "failed"`) que nunca vimos chegar aqui. Não dá pra
            // confirmar o formato real sem capturar uma ocorrência de verdade —
            // loga em warning (nível já capturado em produção) pra pegar a
            // próxima com o payload completo. Mesmo padrão já usado no
            // UazapiWebhookController (commit 31e2667). Remover este log assim
            // que o formato for confirmado e o tratamento real for implementado
            // (ou confirmado que a Covercut não manda esse evento pra nós).
            Log::warning('Covercut webhook: evento não tratado — payload completo abaixo', [
                'canal_id'  => $canal->id,
                'event'     => $event,
                'direction' => $direction,
                'payload'   => $payload,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function validarAssinatura(Request $request, WhatsappCanal $canal): bool
    {
        $segredo = $canal->config['webhook_secret'] ?? null;
        if (! $segredo) {
            return false;
        }

        $assinaturaRecebida = $request->header('X-BSP-Signature', '');
        $assinaturaCalculada = hash_hmac('sha256', $request->getContent(), $segredo);

        return hash_equals($assinaturaCalculada, $assinaturaRecebida);
    }

    private function processarMensagem(array $payload, WhatsappCanal $canal): void
    {
        $tenant = $canal->tenant;

        $messageId = $payload['message']['id'] ?? null;

        if ($messageId && Mensagem::withoutGlobalScopes()->where('provider_message_id', $messageId)->exists()) {
            Log::debug('Covercut webhook: mensagem duplicada ignorada', ['id' => $messageId]);
            return;
        }

        $telefoneRaw = $payload['contact']['wa_id'] ?? null;
        if (! $telefoneRaw) {
            return;
        }
        $telefone = $this->normalizarTelefone($telefoneRaw);
        $pushName = $payload['contact']['name'] ?? null;

        // Achado real 2026-08-20: esse lado nunca validava o pushName (ia
        // direto pro banco, diferente do Uazapi que já rejeitava lixo óbvio)
        // — nem tinha o mesmo tratamento de "atualiza se contato existente
        // ainda não tem nome real". Corrigido pra usar o mesmo validador
        // compartilhado (regra de paridade entre canais).
        $nomeExtracao = app(\App\Services\NomeExtracaoService::class);
        $nomeValido   = $nomeExtracao->pushNameValido($pushName) ? $nomeExtracao->formatarNome($pushName) : null;

        $temReferralAnuncio = isset($payload['message']['referral']) || isset($payload['message']['ctwa_clid']);
        $janelaExpiraEm = $temReferralAnuncio ? now()->addHours(72) : now()->addHours(24);

        $contato = $this->buscarOuCriarContato($telefone, ['nome' => $nomeValido ?: 'Sem Nome', 'origem' => 'whatsapp']);

        if ($nomeValido && $contato->semNomeReal()) {
            $contato->update(['nome' => $nomeValido]);
        }

        VinculoContatoTenant::firstOrCreate(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

        // Achado real (2026-08-12): duas mensagens do mesmo lead chegando quase
        // juntas geravam dois webhooks concorrentes — cada um checava "já tem
        // ticket aberto?" antes do outro terminar de gravar, e os dois criavam
        // ticket (e disparavam a sequência de boas-vindas) pro mesmo contato,
        // duplicando toda mensagem enviada ao lead. A trava garante que só uma
        // requisição por vez resolve/cria o ticket deste contato.
        [$ticket, $ticketNovo] = \Illuminate\Support\Facades\Cache::lock("ticket-resolve:covercut:{$tenant->id}:{$contato->id}", 10)
            ->block(5, function () use ($tenant, $contato, $canal, $janelaExpiraEm, $temReferralAnuncio, $payload) {
                $ticket = TicketAtendimento::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('contato_id', $contato->id)
                    ->whereIn('status', ['aberto', 'aguardando'])
                    ->latest()
                    ->first();

                $ticketNovo = false;

                if ($ticket) {
                    $ticket->update([
                        'whatsapp_canal_id'     => $canal->id,
                        'janela_expira_em'      => $janelaExpiraEm,
                        'janela_origem_anuncio' => $temReferralAnuncio,
                    ]);
                } else {
                    // Achado real (2026-08-12, caso do Eduardo Almada): faltava aqui o
                    // equivalente do UazapiWebhookController — sem essa checagem, um lead
                    // que voltava a falar pelo canal Oficial após o atendimento encerrado
                    // sempre ganhava ticket novo, nunca reabria o anterior. Mesma lógica
                    // de reabertura dos dois canais, extraída pra TicketReaberturaService.
                    $reaberturaService = app(\App\Services\TicketReaberturaService::class);
                    $ticketEncerrado   = $reaberturaService->buscarTicketEncerrado($tenant->id, $contato->id);

                    $textoBruto = $payload['message']['text'] ?? null;
                    $textoBruto = is_string($textoBruto) ? $textoBruto : ($textoBruto['body'] ?? null);

                    if ($ticketEncerrado && $reaberturaService->reabrirSeNecessario($ticketEncerrado, $canal->id, $textoBruto)) {
                        $ticketEncerrado->update([
                            'janela_expira_em'      => $janelaExpiraEm,
                            'janela_origem_anuncio' => $temReferralAnuncio,
                        ]);
                        $ticket = $ticketEncerrado;
                        // ticketNovo permanece false → se reativou, o elseif abaixo dispara
                        // o SdrResponderJob normalmente (agente_responsavel já virou 'bot');
                        // se manteve encerrado, nada é enviado ao lead — mesmo padrão do Uazapi.
                    } elseif ($ticketEncerrado) {
                        $ticket = $ticketEncerrado;
                    } else {
                        $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

                        // Camada 1 de detecção de idioma (Task 4 do roteiro
                        // 2026-08-21): sugere idioma pelo DDI do telefone —
                        // só é aplicado como idioma_lead/idioma_origem quando
                        // bate com o locale do tenant (senão é só uma
                        // sugestão fraca, não confirmação — ver
                        // PaisIdiomaService). Mesma lógica do Uazapi — regra
                        // de paridade entre canais.
                        $idiomaSugerido = app(\App\Services\PaisIdiomaService::class)->sugerirIdioma($contato->telefone);
                        $idiomaBate     = $idiomaSugerido && $idiomaSugerido === $tenant->locale;

                        $ticket = TicketAtendimento::create([
                            'tenant_id'             => $tenant->id,
                            'contato_id'            => $contato->id,
                            'whatsapp_canal_id'     => $canal->id,
                            'coluna_kanban'         => KanbanColuna::chaveDeEntrada($tenant->id),
                            'agente_responsavel'    => 'bot',
                            'sdr_persona_id'        => $persona?->id,
                            'status'                => 'aberto',
                            'origem'                => $temReferralAnuncio ? 'anuncio_meta' : 'whatsapp',
                            'aberto_em'             => now(),
                            'janela_expira_em'      => $janelaExpiraEm,
                            'janela_origem_anuncio' => $temReferralAnuncio,
                            'idioma_pais_ddi'       => $idiomaSugerido,
                            'idioma_lead'           => $idiomaBate ? substr($idiomaSugerido, 0, 2) : null,
                            'idioma_origem'         => $idiomaBate ? 'ddi' : null,
                            'idioma_atualizado_em'  => $idiomaBate ? now() : null,
                        ]);
                        $ticketNovo = true;

                        // Camada 2 (Task 8 do roteiro 2026-08-21): quando o DDI sugeriu
                        // um idioma mas ele diverge do locale do tenant, a sugestão
                        // fraca da Camada 1 não foi aplicada acima — em vez de ficar
                        // sem idioma nenhum até a Camada 3 (IA) agir na próxima
                        // mensagem, pergunta explicitamente ao lead qual idioma prefere.
                        // Mesma lógica do Uazapi — regra de paridade entre canais.
                        if ($idiomaSugerido && ! $idiomaBate) {
                            app(\App\Services\IdiomaEscolhaService::class)->enviarEscolha(
                                $ticket, ['pt-BR' => 'Português', 'en-US' => 'English', 'es-ES' => 'Español']
                            );
                        }
                    }
                }

                return [$ticket, $ticketNovo];
            });

        [$conteudo, $tipoMensagem, $midiaUrl] = $this->resolverConteudoEMidia($payload['message'] ?? [], $canal, $ticket, $messageId);

        if (! $conteudo) {
            // Tipos genuinamente não tratados (ex.: unsupported, sticker, poll,
            // location, contacts) continuam só logados aqui.
            Log::info('Covercut webhook: mensagem não-texto ignorada (MVP)', [
                'message_id' => $messageId,
                'type'       => $payload['message']['type'] ?? null,
            ]);
        }

        // Extração progressiva de nome a partir do conteúdo (texto ou
        // transcrição de áudio) — achado real (2026-08-14): essa lógica
        // vivia só do lado Uazapi, um lead que ligou (Secretária Eletrônica)
        // e se identificou por áudio no canal Oficial nunca tinha o nome
        // capturado. Mesmo padrão do UazapiWebhookController, agora
        // compartilhado via NomeExtracaoService — regra de paridade entre
        // canais do CLAUDE.md.
        if ($conteudo && $contato->semNomeReal()) {
            $nomeExtraido = app(\App\Services\NomeExtracaoService::class)->extrairDaMensagem($conteudo);
            if ($nomeExtraido) {
                $contato->update(['nome' => $nomeExtraido]);
            }
        }

        // Item 11 do roteiro (2026-08-20) + Task 5 do roteiro de idioma
        // multilíngue (2026-08-21): detecção roda em toda mensagem elegível
        // (texto/áudio, não-placeholder) — não só na primeira, já que a
        // Camada 1 (Task 4) pode ter pré-preenchido idioma_lead pelo DDI na
        // criação do ticket. A Camada 3 (IA) age como rede de segurança pros
        // casos em que o DDI errou, mas só ATUALIZA idioma_lead/idioma_origem
        // quando: (a) ainda não havia idioma nenhum, ou (b) a regra
        // anti-oscilação aprova a troca (deveAtualizarIdiomaLead) E o
        // idioma_origem atual não está travado por escolha explícita
        // ('botao', Camada 2) ou manual ('manual', Camada 4) — a IA nunca
        // sobrepõe silenciosamente uma decisão humana. A tradução em si
        // usa o idioma real do atendente atribuído ao ticket (ou o locale
        // do tenant, sem atendente) como alvo — não mais fixo em 'pt'.
        $idiomaMensagem = null;
        $conteudoPt     = null;
        if ($conteudo && in_array($tipoMensagem, ['texto', 'audio'], true)
            && ! str_starts_with(trim($conteudo), '[')) {
            $traducao = app(\App\Services\TraducaoService::class);

            $idiomaDetectado = $traducao->detectarIdioma($conteudo);
            if ($idiomaDetectado) {
                $idiomaAtual = $ticket->idioma_lead;

                // Regra de prioridade da spec: escolha explícita (Camada 2) e
                // alteração manual (Camada 4) só perdem pra uma nova escolha
                // explícita/manual — a IA nunca sobrepõe silenciosamente uma
                // vez que o cliente ou o atendente já decidiu.
                $idiomaTravado = in_array($ticket->idioma_origem, ['botao', 'manual'], true);

                if (! $idiomaAtual) {
                    // Primeira detecção do ticket — sempre aceita, sem regra anti-oscilação
                    // (não há "idioma atual" ainda pra comparar).
                    $ticket->update(['idioma_lead' => $idiomaDetectado, 'idioma_origem' => 'ia', 'idioma_atualizado_em' => now()]);
                    $idiomaAtual = $idiomaDetectado;
                } elseif ($idiomaDetectado !== $idiomaAtual && ! $idiomaTravado) {
                    $ultimasMensagens = \App\Models\Mensagem::withoutGlobalScopes()
                        ->where('ticket_id', $ticket->id)->where('remetente', 'lead')
                        ->orderByDesc('enviado_em')->limit(2)->pluck('idioma')->reverse()->values();

                    if ($traducao->deveAtualizarIdiomaLead($idiomaAtual, $idiomaDetectado, $ultimasMensagens, $conteudo)) {
                        $ticket->update(['idioma_lead' => $idiomaDetectado, 'idioma_origem' => 'ia', 'idioma_atualizado_em' => now()]);
                        $idiomaAtual = $idiomaDetectado;
                    }
                }

                $idiomaMensagem = $idiomaDetectado;

                // Achado real (revisão da Task 5): o alvo de tradução não é
                // mais o literal 'pt' — é o idioma resolvido do atendente
                // (resolverIdiomaAtendente), que pode ser qualquer locale
                // (ex.: 'es-ES'). Comparar contra o alvo (2 primeiras letras,
                // já que detectarIdioma() devolve ISO 639-1 de 2 letras e o
                // alvo pode vir como locale de 5) evita dois bugs: deixar de
                // traduzir quando o cliente escreve em português mas o
                // atendente lê em outro idioma, e desperdiçar chamada de IA
                // pedindo pra "traduzir pra 'es-ES'" (não é um código ISO
                // 639-1 válido) quando o idioma já bate.
                $idiomaAlvo = $traducao->resolverIdiomaAtendente($ticket->vendedor_id, $tenant->locale);
                if (substr($idiomaAlvo, 0, 2) !== $idiomaDetectado) {
                    $conteudoPt = $traducao->traduzir($conteudo, $idiomaAlvo, $idiomaDetectado);
                }
            }
        }

        if ($conteudo) {
            Mensagem::create([
                'ticket_id'            => $ticket->id,
                'tenant_id'            => $tenant->id,
                'remetente'            => 'lead',
                'tipo'                 => $tipoMensagem,
                'conteudo'             => $conteudo,
                'idioma'               => $idiomaMensagem,
                'conteudo_pt'          => $conteudoPt,
                'midia_url'            => $midiaUrl,
                'provider_message_id'  => $messageId,
                'enviado_em'           => now(),
            ]);
        }

        // Achado real 2026-08-20 (Leonardo): NÃO ecoar a transcrição do áudio
        // do CLIENTE de volta pra ele mesmo — ele já sabe o que falou, isso
        // só existe pra o eco do lado do ATENDENTE (ver os outros pontos que
        // chamam EcoTranscricaoService com $ticket->nomePersonaDisplay()),
        // onde faz sentido porque o cliente pode não conseguir ouvir áudio.
        // A transcrição em si continua disponível pro sistema/IA normalmente
        // — ela já é salva como o próprio conteúdo da mensagem do lead
        // (`[Áudio transcrito: ...]`), esse eco era só uma duplicata enviada
        // de volta pro WhatsApp do cliente, sem função nenhuma. (Ver mesma
        // remoção espelhada em UazapiWebhookController.)

        if ($ticket->followup_estagio_enviado !== 0) {
            $ticket->update(['followup_estagio_enviado' => 0]);
        }

        if ($ticketNovo) {
            app(SequenciaService::class)->iniciarParaTicket($ticket);
        } elseif ($ticket->agente_responsavel === 'bot' && $conteudo) {
            dispatch(new SdrResponderJob($ticket->id, $conteudo, false, false, 0));
        }
    }

    /**
     * Resolve conteúdo, tipo de mensagem e URL de mídia a partir de `message.type`
     * — usado tanto para mensagens do lead (inbound) quanto para mensagens do
     * atendente enviadas pelo WhatsApp Business App (echo/outbound/phone), já
     * que a Covercut usa exatamente o mesmo formato de `message` nos dois casos.
     * Extraído pra um lugar só justamente pra evitar o tipo de divergência que
     * causou o card ficar sem a mensagem de orçamento do André Inácio no Uazapi
     * (2026-08-03): se cada fluxo reimplementasse essa leitura por conta própria,
     * uma correção de tipo de mídia feita num lado facilmente esqueceria do outro.
     *
     * @return array{0: ?string, 1: string, 2: ?string} [conteudo, tipoMensagem, midiaUrl]
     */
    private function resolverConteudoEMidia(array $message, WhatsappCanal $canal, TicketAtendimento $ticket, ?string $messageId): array
    {
        // `message.text` chega como STRING simples no payload real da Covercut
        // (ex.: "text": "Ola"), não como objeto `{body: ...}` — o formato Meta
        // Cloud API "cru" seria `text.body`, então a leitura tolera os dois.
        $tipo         = $message['type'] ?? null;
        $conteudo     = null;
        $tipoMensagem = 'texto';
        $midiaUrl     = null;

        $colunaConfig     = KanbanColunaConfig::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->first();
        $transcricaoAtiva = $colunaConfig?->transcricao_ativa ?? true;

        if ($tipo === 'text') {
            $conteudo = $message['text']['body'] ?? ($message['text'] ?? null);
        } elseif ($tipo === 'audio') {
            try {
                $conteudo = app(MediaProcessorService::class)->processarOficial($message, $canal, null, $transcricaoAtiva);
                if ($conteudo !== null) {
                    $tipoMensagem = 'audio';
                    $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($message, $canal, 'audio');
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar áudio', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        } elseif ($tipo === 'image') {
            try {
                $focoAnalise = $colunaConfig?->foco_analise_imagem;

                // Download único + 1 chamada de visão que já devolve descrição e
                // itens juntos — antes disso a mesma imagem era baixada até 3x e
                // passava por 2 chamadas de IA separadas, o que sob volume (2+
                // imagens seguidas) estourava timeout e deixava o card sem os
                // itens mesmo com a descrição salva certinho (achado real
                // 2026-08-15, ticket 3085).
                $resultado    = app(MediaProcessorService::class)->processarImagemUnicaOficial($message, $canal, $focoAnalise, $transcricaoAtiva);
                $conteudo     = $resultado['conteudo'];
                $tipoMensagem = 'imagem';
                $midiaUrl     = $resultado['midiaUrl'];

                if ($resultado['itens']) {
                    $listaAtual = $ticket->lista_itens ? $ticket->lista_itens . "\n" : '';
                    $ticket->update(['lista_itens' => $listaAtual . $resultado['itens']]);
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar imagem', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        } elseif ($tipo === 'video') {
            try {
                $conteudo = app(MediaProcessorService::class)->processarOficial($message, $canal);
                $tipoMensagem = 'video';
                $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($message, $canal, 'video');
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar vídeo', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        } elseif ($tipo === 'document') {
            // Paridade com o Uazapi: documento nunca guarda midia_url nem usa o
            // enum 'documento' de fato — sempre tipo 'texto' com placeholder.
            $conteudo = app(MediaProcessorService::class)->processarOficial($message, $canal);
        }

        if ($conteudo === null && ! in_array($tipo, ['audio', 'image', 'video', 'document'], true)) {
            // Restaura a semântica pré-Task-1: message.text era lido incondicionalmente,
            // independente de message.type. Cobre payloads reais onde o type vem
            // ausente, com grafia diferente, ou um valor não previsto — sticker/
            // unsupported continuam sem message.text, então nada muda pra eles.
            $conteudo = $message['text']['body'] ?? ($message['text'] ?? null);
        }

        return [$conteudo, $tipoMensagem, $midiaUrl];
    }

    /**
     * Mensagem enviada pelo atendente direto pelo WhatsApp Business App (modo
     * Coexistence), fora da API — equivalente ao fluxo `fromMe && !viaApi` do
     * UazapiWebhookController::transferirParaHumano(). Sem isso, toda resposta
     * manual pelo celular no número oficial desaparecia da conversa do card
     * (achado em 2026-08-03 ao investigar o mesmo problema no Uazapi).
     */
    private function processarMensagemHumana(array $payload, WhatsappCanal $canal): void
    {
        $messageId = $payload['message']['id'] ?? null;

        if ($messageId && Mensagem::withoutGlobalScopes()->where('provider_message_id', $messageId)->exists()) {
            Log::debug('Covercut webhook: mensagem (echo/phone) duplicada ignorada', ['id' => $messageId]);
            return;
        }

        $telefoneRaw = $payload['contact']['wa_id'] ?? null;
        if (! $telefoneRaw) {
            return;
        }
        $telefone = $this->normalizarTelefone($telefoneRaw);

        $contato = Contato::where('telefone', $telefone)->first();
        if (! $contato) {
            return;
        }

        $tenant = $canal->tenant;

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
        // e muda responsável para humano se ainda estava com o bot — mesma
        // semântica de UazapiWebhookController::transferirParaHumano().
        $updates = [];
        if ($ticket->whatsapp_canal_id !== $canal->id) {
            $updates['whatsapp_canal_id'] = $canal->id;
        }
        if ($ticket->agente_responsavel === 'bot') {
            $updates['agente_responsavel'] = 'humano';
            Log::info("Ticket #{$ticket->id} transferido para humano (resposta pelo WhatsApp Business App / Coexistence)");
        }
        if ($updates) {
            $ticket->update($updates);
        }

        [$conteudo, $tipoMensagem, $midiaUrl] = $this->resolverConteudoEMidia($payload['message'] ?? [], $canal, $ticket, $messageId);

        if (! $conteudo) {
            // Não usa placeholder aqui (diferente do Uazapi): tipos sem conteúdo
            // aqui costumam ser reação/status, não algo digno de virar linha na
            // conversa — mas loga o payload bruto pra confirmar isso na prática.
            Log::warning('Covercut webhook: mensagem (echo/phone) sem conteúdo reconhecido — payload completo abaixo', [
                'tenant_id' => $tenant->id,
                'ticket_id' => $ticket->id,
                'telefone'  => $telefone,
                'message'   => $payload['message'] ?? null,
            ]);
            return;
        }

        Mensagem::create([
            'ticket_id'           => $ticket->id,
            'tenant_id'           => $tenant->id,
            'remetente'           => 'humano',
            'tipo'                => $tipoMensagem,
            'conteudo'            => $conteudo,
            'midia_url'           => $midiaUrl,
            'provider_message_id' => $messageId,
            'enviado_em'          => now(),
        ]);

        // Ecoa a transcrição do áudio de volta na própria conversa do WhatsApp,
        // igual ao áudio do lead — mesmo que o atendente tenha gravado pelo
        // WhatsApp Business App (Coexistence), quem estiver acompanhando a
        // conversa pelo app consegue ler sem precisar tocar o áudio.
        if ($tipoMensagem === 'audio') {
            $transcricaoBruta = app(MediaProcessorService::class)->extrairTranscricaoBruta($conteudo);
            if ($transcricaoBruta) {
                app(EcoTranscricaoService::class)->enviar($canal, $ticket, $telefone, $transcricaoBruta, $ticket->nomePersonaDisplay());
            }
        }
    }

    /**
     * Busca um contato pelo telefone ou cria um novo — tolerante à corrida com o
     * job `contatos:sincronizar-google` (roda a cada 6h), que pode inserir o mesmo
     * telefone entre o SELECT e o INSERT do firstOrCreate normal. Sem essa proteção,
     * a exceção de chave duplicada derrubava a requisição inteira do webhook e a
     * mensagem do lead nunca chegava a ser salva. Mesma semântica de
     * UazapiWebhookController::buscarOuCriarContato() — duplicada aqui de propósito
     * (controller autocontido, não reusa código do UazapiWebhookController).
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

    private function normalizarTelefone(string $telefone): string
    {
        $normalizado = app(TelefoneService::class)->normalizar($telefone);
        if ($normalizado) {
            return $normalizado;
        }
        $digits = preg_replace('/\D/', '', $telefone);
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = '55' . $digits;
        }
        Log::warning('Covercut webhook: telefone não normalizável', ['raw' => $telefone, 'fallback' => $digits]);
        return $digits;
    }
}
