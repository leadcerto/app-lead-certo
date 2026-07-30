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

        if (($payload['event'] ?? null) !== 'message' || ($payload['direction'] ?? null) !== 'inbound') {
            return response()->json(['ok' => true]); // evento que não é mensagem de entrada — ignora silenciosamente
        }

        $this->processarMensagem($payload, $canal);

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

        $temReferralAnuncio = isset($payload['message']['referral']) || isset($payload['message']['ctwa_clid']);
        $janelaExpiraEm = $temReferralAnuncio ? now()->addHours(72) : now()->addHours(24);

        $contato = $this->buscarOuCriarContato($telefone, ['nome' => $pushName ?: 'Sem Nome', 'origem' => 'whatsapp']);

        VinculoContatoTenant::firstOrCreate(['contato_id' => $contato->id, 'tenant_id' => $tenant->id]);

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
            $persona = $tenant->personas()->where('is_default', true)->where('ativo', true)->first();

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
            ]);
            $ticketNovo = true;
        }

        // `message.text` chega como STRING simples no payload real da Covercut
        // (ex.: "text": "Ola"), não como objeto `{body: ...}` — o formato Meta
        // Cloud API "cru" seria `text.body`, então a leitura tolera os dois.
        $tipo         = $payload['message']['type'] ?? null;
        $conteudo     = null;
        $tipoMensagem = 'texto';
        $midiaUrl     = null;

        if ($tipo === 'text') {
            $conteudo = $payload['message']['text']['body'] ?? ($payload['message']['text'] ?? null);
        } elseif ($tipo === 'audio') {
            try {
                $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
                if ($conteudo !== null) {
                    $tipoMensagem = 'audio';
                    $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'audio');
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar áudio', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        } elseif ($tipo === 'image') {
            try {
                $focoAnalise = KanbanColunaConfig::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('coluna_kanban', $ticket->coluna_kanban)
                    ->value('foco_analise_imagem');

                $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal, $focoAnalise);
                if ($conteudo !== null) {
                    $tipoMensagem = 'imagem';
                    $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'image');

                    $itens = app(MediaProcessorService::class)->extrairItensImagemOficial($payload['message'], $canal, $focoAnalise);
                    if ($itens) {
                        $listaAtual = $ticket->lista_itens ? $ticket->lista_itens . "\n" : '';
                        $ticket->update(['lista_itens' => $listaAtual . $itens]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Covercut webhook: falha ao processar imagem', ['message_id' => $messageId, 'erro' => $e->getMessage()]);
            }
        } elseif ($tipo === 'video') {
            $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
            $tipoMensagem = 'video';
            $midiaUrl = app(MediaProcessorService::class)->baixarEPersistirUrlOficial($payload['message'], $canal, 'video');
        } elseif ($tipo === 'document') {
            // Paridade com o Uazapi: documento nunca guarda midia_url nem usa o
            // enum 'documento' de fato — sempre tipo 'texto' com placeholder.
            $conteudo = app(MediaProcessorService::class)->processarOficial($payload['message'], $canal);
        }

        if (! $conteudo) {
            // Tipos genuinamente não tratados (ex.: unsupported, sticker, poll,
            // location, contacts) continuam só logados aqui.
            Log::info('Covercut webhook: mensagem não-texto ignorada (MVP)', [
                'message_id' => $messageId,
                'type'       => $tipo,
            ]);
        }

        if ($conteudo) {
            Mensagem::create([
                'ticket_id'            => $ticket->id,
                'tenant_id'            => $tenant->id,
                'remetente'            => 'lead',
                'tipo'                 => $tipoMensagem,
                'conteudo'             => $conteudo,
                'midia_url'            => $midiaUrl,
                'provider_message_id'  => $messageId,
                'enviado_em'           => now(),
            ]);
        }

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
