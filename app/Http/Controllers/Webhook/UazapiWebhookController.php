<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\PapelColunaKanban;
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
use App\Services\MediaProcessorService;
use App\Services\OpenRouterService;
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
        if ($messageId && Mensagem::withoutGlobalScopes()->where('uazapi_message_id', $messageId)->exists()) {
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
            $this->processarChamadaWhatsApp($tenant, $telefone, $pushName);
            return;
        }

        if ($fromMe) {
            // Franqueado respondeu pelo celular físico — passa para humano
            if (! $viaApi) {
                $this->transferirParaHumano($tenant, $telefone, $conteudo, $msg, $tenant->uazapi_instance_token);
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
                ->whereIn('coluna_kanban', KanbanColuna::chavesComPapel($tenant->id, PapelColunaKanban::Encerramento))
                ->latest()
                ->first();

            if ($ticketEncerrado) {
                // Nem toda mensagem pra um ticket encerrado deve reabrir o atendimento
                // — uma despedida/agradecimento ("obrigado, já consegui") não precisa
                // reabrir, mas informação útil de verdade precisa. A IA decide.
                if ($this->deveReabrirTicketEncerrado($conteudo)) {
                    // Volta pra coluna em que estava antes de encerrar — independente de
                    // quem encerrou (humano, silêncio automático ou a própria IA).
                    $colunaRestaurada = $ticketEncerrado->coluna_antes_encerrar ?: 'em_atendimento';

                    $ticketEncerrado->update([
                        'status'                => 'aberto',
                        'agente_responsavel'    => 'bot',
                        'coluna_kanban'         => $colunaRestaurada,
                        'coluna_antes_encerrar' => null,
                    ]);
                    Log::info("Webhook: ticket #{$ticketEncerrado->id} reativado, voltou pra coluna '{$colunaRestaurada}'");
                } else {
                    Log::info("Webhook: ticket #{$ticketEncerrado->id} recebeu mensagem mas continua encerrado (parece despedida/agradecimento)");
                }

                $ticket = $ticketEncerrado;
                // ticketNovo permanece false → se reativou, cai no elseif abaixo →
                // SdrResponderJob; se não, agente_responsavel continua como estava
                // (não 'bot'), então o elseif não dispara e nada é enviado ao lead.
            } else {
                // Abre novo ticket
                $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

                $ticket = TicketAtendimento::create([
                    'tenant_id'          => $tenant->id,
                    'contato_id'         => $contato->id,
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

        // Processa mídia se houver (imagem → visão IA / áudio → transcrição / etc)
        $mediaType = $msg['mediaType'] ?? null;
        $tipoMensagem = 'texto';
        $midiaUrl = null;
        if ($mediaType && $instanceToken) {
            try {
                $focoAnalise = $mediaType === 'image'
                    ? KanbanColunaConfig::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)
                        ->where('coluna_kanban', $ticket->coluna_kanban)
                        ->value('foco_analise_imagem')
                    : null;

                $processado = app(MediaProcessorService::class)->processar($msg, $instanceToken, $focoAnalise);
                if ($processado !== null) {
                    $conteudo     = $processado;
                    $tipoMensagem = match ($mediaType) {
                        'image' => 'imagem', 'video' => 'video', 'audio' => 'audio', default => 'texto',
                    };
                    if (in_array($mediaType, ['image', 'audio', 'video'])) {
                        $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrl($msg, $instanceToken, $mediaType);
                    }

                    // Acumula os itens identificados na imagem no card, pra quem
                    // vende ver de relance o que já foi enviado sem reabrir cada foto.
                    if ($mediaType === 'image') {
                        $itens = app(MediaProcessorService::class)->extrairItensImagem($msg, $instanceToken, $focoAnalise);
                        if ($itens) {
                            $listaAtual = $ticket->lista_itens ? $ticket->lista_itens . "\n" : '';
                            $ticket->update(['lista_itens' => $listaAtual . $itens]);
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
                'ticket_id'         => $ticket->id,
                'tenant_id'         => $tenant->id,
                'remetente'         => 'lead',
                'tipo'              => $tipoMensagem,
                'conteudo'          => $conteudo,
                'midia_url'         => $midiaUrl,
                'uazapi_message_id' => $msg['messageid'] ?? null,
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

    private function processarChamadaWhatsApp(Tenant $tenant, string $telefone, ?string $pushName): void
    {
        // Ignora se já há ticket ativo (evita duplicar sequência)
        $contato = $this->buscarOuCriarContato($telefone, ['nome' => $pushName ?: 'Sem Nome', 'origem' => 'whatsapp']);

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
            // Expressões religiosas/interjeições que brasileiros costumam escrever
            // com maiúscula por respeito — nunca são o nome de quem escreveu
            'deus', 'jesus', 'cristo', 'senhor', 'senhora', 'graças', 'glória',
            'amém', 'aleluia', 'espírito', 'divino', 'pai', 'fiel', 'bendito',
            'abençoado', 'abençoada', 'aleluya',
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
     * Decide se uma mensagem nova pra um ticket encerrado deve reabrir o
     * atendimento. Despedidas/agradecimentos ("obrigado", "já consegui",
     * "tchau") não devem reabrir; qualquer informação útil de verdade deve.
     * Em caso de dúvida ou falha da IA, opta por reabrir — perder uma venda
     * por não reabrir é pior do que reabrir um agradecimento por engano.
     */
    private function deveReabrirTicketEncerrado(?string $mensagem): bool
    {
        if (! $mensagem || trim($mensagem) === '') {
            return true;
        }

        $resposta = app(OpenRouterService::class)->chat([
            ['role' => 'system', 'content' =>
                'Você analisa mensagens de um cliente cujo atendimento de frete/mudança JÁ FOI ENCERRADO. '
                . 'Decida se essa nova mensagem precisa REABRIR o atendimento (nova dúvida, informação útil '
                . 'pro serviço, reclamação, pedido de continuidade) ou se é só uma despedida/agradecimento que '
                . 'NÃO precisa reabrir (ex: "obrigado", "já consegui", "tchau", "ok", emoji de agradecimento). '
                . 'Responda com exatamente uma palavra: REABRIR ou MANTER.'],
            ['role' => 'user', 'content' => $mensagem],
        ], 'simples', 10, 'reabertura_ticket_encerrado');

        if (! $resposta) {
            return true;
        }

        return ! str_contains(mb_strtoupper($resposta), 'MANTER');
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

    private function transferirParaHumano(Tenant $tenant, string $telefone, ?string $conteudo, array $msg = [], string $instanceToken = ''): void
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

        // Processa mídia (imagem/áudio/vídeo enviados pelo WhatsApp Web/celular) —
        // mesma lógica usada pra mensagens do lead, senão a mídia não aparece no card.
        $mediaType = $msg['mediaType'] ?? null;
        $tipoMensagem = 'texto';
        $midiaUrl = null;
        if ($mediaType && $instanceToken && in_array($mediaType, ['image', 'audio', 'video'])) {
            try {
                $midiaUrl     = app(MediaProcessorService::class)->baixarEPersistirUrl($msg, $instanceToken, $mediaType);
                $tipoMensagem = match ($mediaType) {
                    'image' => 'imagem', 'video' => 'video', default => 'audio',
                };
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

        // Salva a mensagem enviada pelo franqueado
        if ($conteudo) {
            Mensagem::create([
                'ticket_id'         => $ticket->id,
                'tenant_id'         => $tenant->id,
                'remetente'         => 'humano',
                'tipo'              => $tipoMensagem,
                'conteudo'          => $conteudo,
                'midia_url'         => $midiaUrl,
                'uazapi_message_id' => $msg['messageid'] ?? null,
                'enviado_em'        => now(),
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
