<?php

namespace App\Services;

use App\Models\WhatsappCanal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MediaProcessorService
{
    private string $openRouterKey;
    private string $groqKey;
    private string $uazapiBaseUrl;

    public function __construct()
    {
        $this->openRouterKey = (string) config('services.openrouter.key', '');
        $this->groqKey       = (string) config('services.groq.key', '');
        $this->uazapiBaseUrl = rtrim(config('services.uazapi.base_url', ''), '/');
    }

    /**
     * Processa qualquer tipo de mídia e retorna texto descritivo para o bot.
     * Retorna null se não há mídia ou não conseguiu processar.
     * $focoAnalise vem da configuração da coluna (foco_analise_imagem) — o que a
     * IA deve procurar na imagem varia por negócio (móveis, placas, cores, etc.).
     * $transcricaoAtiva vem de kanban_coluna_configs.transcricao_ativa — quando
     * false, pula a chamada de IA (Whisper/visão) mas ainda retorna um
     * placeholder não-nulo, pra mídia continuar sendo baixada e salva no card
     * normalmente (só a análise de conteúdo é que é pulada).
     */
    public function processar(array $msg, string $instanceToken, ?string $focoAnalise = null, bool $transcricaoAtiva = true): ?string
    {
        $mediaType = $msg['mediaType'] ?? null;

        if (! $mediaType) {
            return null;
        }

        if (! $transcricaoAtiva && in_array($mediaType, ['image', 'audio'], true)) {
            return $mediaType === 'image'
                ? '[Imagem recebida — transcrição desativada para esta coluna]'
                : '[Áudio recebido — transcrição desativada para esta coluna]';
        }

        return match ($mediaType) {
            'image'    => $this->processarImagem($msg, $instanceToken, $focoAnalise),
            'audio'    => $this->processarAudio($msg, $instanceToken),
            'video'    => $this->processarVideo($msg, $instanceToken),
            'document' => $this->processarDocumento($msg),
            default    => null,
        };
    }

    // -------------------------------------------------------------------------
    // Imagem → visão IA
    // -------------------------------------------------------------------------

    private function processarImagem(array $msg, string $instanceToken, ?string $focoAnalise = null): string
    {
        $caption  = is_string($msg['content'] ?? null) ? ($msg['content'] ?? '') : '';
        $mediaUrl = $this->obterUrlImagem($msg, $instanceToken);

        if (! $mediaUrl) {
            Log::warning('MediaProcessor: não encontrou URL de imagem', ['msg' => array_keys($msg)]);
            $caption = $caption ?: '[Imagem recebida]';
            return $caption;
        }

        $descricao = $this->descreverImagemComVisao($mediaUrl, $caption, $focoAnalise);
        $prefixo   = $caption ? "[Imagem: {$caption}] " : '[Imagem] ';

        return $prefixo . $descricao;
    }

    /**
     * Gera uma lista curta dos itens vistos na imagem, focada no que a coluna
     * configurou (ou num foco genérico se não configurado) — usada pra alimentar
     * o campo "Itens identificados" do card, separado da descrição narrativa que
     * vai pro contexto do agente. Retorna null se não conseguir processar.
     */
    public function extrairItensImagem(array $msg, string $instanceToken, ?string $focoAnalise = null, bool $transcricaoAtiva = true): ?string
    {
        if (! $transcricaoAtiva || ! $this->openRouterKey) {
            return null;
        }

        $mediaUrl = $this->obterUrlImagem($msg, $instanceToken);
        if (! $mediaUrl) {
            return null;
        }

        return $this->chamarVisaoParaItens($mediaUrl, $focoAnalise);
    }

    private function chamarVisaoParaItens(string $mediaUrl, ?string $focoAnalise = null): ?string
    {
        $foco = trim($focoAnalise ?: self::FOCO_PADRAO);

        $modelosVision = FreeModelsService::vision();
        if (count($modelosVision) < 3) {
            $modelosVision[] = self::VISAO_PAGO_FALLBACK;
        }

        $prompt = "Liste em tópicos curtos (um item por linha, começando com '-') o que aparece na imagem "
            . "relacionado a: {$foco}. Seja objetivo, sem frases longas — só o essencial de cada item "
            . "(ex: '- Sofá 3 lugares'). Se nada relevante aparecer, responda apenas 'Nada identificado'.";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openRouterKey}",
                'HTTP-Referer'  => config('app.url', 'https://app.leadcerto.app.br'),
                'X-Title'       => 'Lead Certo',
            ])->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
                'models'   => $modelosVision,
                'route'    => 'fallback',
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => $mediaUrl]],
                        ['type' => 'text',      'text'      => $prompt],
                    ],
                ]],
                'max_tokens' => 200,
            ]);

            if ($response->successful()) {
                $texto = trim($response->json('choices.0.message.content') ?? '');
                return ($texto && ! str_contains(mb_strtolower($texto), 'nada identificado')) ? $texto : null;
            }

            Log::warning('MediaProcessor: extração de itens falhou', ['status' => $response->status()]);
        } catch (\Exception $e) {
            Log::error('MediaProcessor: extração de itens exceção', ['erro' => $e->getMessage()]);
        }

        return null;
    }

    private function obterUrlImagem(array $msg, string $instanceToken): ?string
    {
        $descriptografado = $this->descriptografarMidiaWhatsApp($msg, 'image');

        return $descriptografado
            ? 'data:' . ($descriptografado['mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($descriptografado['bytes'])
            : ($this->extrairUrl($msg) ?? $this->baixarUrlViaUazapi($instanceToken, $msg));
    }

    // Modelo pago de visão — último recurso se todos os gratuitos falharem
    private const VISAO_PAGO_FALLBACK = 'google/gemini-flash-1.5-8b';

    // Foco padrão quando a coluna não configura foco_analise_imagem — precisa
    // ser genérico (2026-08-05, achado pelo Leonardo): o sistema é multi-tenant,
    // cada negócio tem seu próprio foco (frete/mudança é só a empresa usada
    // hoje como referência de teste, não deve ficar hardcoded no padrão).
    private const FOCO_PADRAO = 'os itens, objetos ou elementos que aparecem em destaque na imagem';

    private function descreverImagemComVisao(string $imageUrl, string $caption = '', ?string $focoAnalise = null): string
    {
        if (! $this->openRouterKey) {
            return '[Imagem recebida — processamento de visão não configurado]';
        }

        // Modelos gratuitos atualizados diariamente + 1 pago como último recurso (≤ 3 total)
        $modelosVision = FreeModelsService::vision();
        if (count($modelosVision) < 3) {
            $modelosVision[] = self::VISAO_PAGO_FALLBACK;
        }

        $foco = trim($focoAnalise ?: self::FOCO_PADRAO);

        $promptContexto = 'Você é um assistente de uma empresa. '
            . 'Descreva em português o que vê na imagem de forma objetiva e prática, '
            . "focando em: {$foco}. Se não for relevante, descreva brevemente o que vê.";

        if ($caption) {
            $promptContexto .= " O remetente adicionou a legenda: \"{$caption}\".";
        }

        try {
            // OpenRouter route=fallback tenta cada modelo em ordem até um responder
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openRouterKey}",
                'HTTP-Referer'  => config('app.url', 'https://app.leadcerto.app.br'),
                'X-Title'       => 'Lead Certo',
            ])->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
                'models' => $modelosVision,
                'route'  => 'fallback',
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]],
                        ['type' => 'text',      'text'      => $promptContexto],
                    ],
                ]],
                'max_tokens' => 300,
            ]);

            if ($response->successful()) {
                $modeloUsado = $response->json('model') ?? 'desconhecido';
                Log::debug('MediaProcessor visão OK', ['modelo' => $modeloUsado]);
                return trim($response->json('choices.0.message.content') ?? '[Imagem recebida]');
            }

            Log::warning('MediaProcessor visão falhou', ['status' => $response->status(), 'body' => substr($response->body(), 0, 200)]);
        } catch (\Exception $e) {
            Log::error('MediaProcessor visão exception', ['erro' => $e->getMessage()]);
        }

        return '[Imagem recebida — não foi possível analisar o conteúdo]';
    }

    // -------------------------------------------------------------------------
    // Áudio / PTT → transcrição Whisper (via Groq)
    // -------------------------------------------------------------------------

    private function processarAudio(array $msg, string $instanceToken): string
    {
        if (! $this->groqKey) {
            return '[Áudio recebido — transcrição não configurada]';
        }

        // Áudio do WhatsApp vem criptografado (.enc) — descriptografa com o mediaKey
        // do próprio payload; só recorre ao Uazapi se por algum motivo não der certo.
        $descriptografado = $this->descriptografarMidiaWhatsApp($msg, 'audio');
        $midia = $descriptografado
            ? ['base64' => base64_encode($descriptografado['bytes']), 'mime' => $descriptografado['mime'] ?: 'audio/ogg']
            : $this->baixarMidiaDoUazapi($instanceToken, $msg);

        if (! $midia) {
            Log::warning('MediaProcessor: não conseguiu baixar áudio', ['messageid' => $msg['messageid'] ?? null]);
            return '[Áudio recebido — não foi possível transcrever]';
        }

        $transcricao = $this->transcreverAudioBase64($midia['base64'], $midia['mime']);

        return $transcricao
            ? "[Áudio transcrito: {$transcricao}]"
            : '[Áudio recebido — não foi possível transcrever]';
    }

    /**
     * Transcreve um arquivo de áudio já em mãos (bytes brutos), sem passar pelo
     * download/descriptografia do WhatsApp — usado quando o atendente grava ou
     * anexa o áudio direto no painel (KanbanController::enviarMidia()), onde o
     * arquivo já está em disco, não vem de um payload de webhook.
     */
    public function transcreverArquivo(string $bytes, string $mime, bool $transcricaoAtiva = true): ?string
    {
        if (! $transcricaoAtiva || ! $this->groqKey) {
            return null;
        }

        return $this->transcreverAudioBase64(base64_encode($bytes), $mime);
    }

    private function transcreverAudioBase64(string $base64, string $mime): ?string
    {
        try {
            $audioContent = base64_decode($base64);
            $extensao     = str_contains($mime, 'ogg') ? 'ogg'
                          : (str_contains($mime, 'mp4') ? 'mp4'
                          : (str_contains($mime, 'mpeg') ? 'mp3'
                          : (str_contains($mime, 'webm') ? 'webm' : 'ogg')));

            $response = Http::withHeaders(['Authorization' => "Bearer {$this->groqKey}"])
                ->timeout(60)
                ->attach('file', $audioContent, "audio.{$extensao}")
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model'    => 'whisper-large-v3-turbo',
                    'language' => 'pt',
                ]);

            if ($response->successful()) {
                $texto = trim($response->json('text') ?? '');
                Log::debug('MediaProcessor Whisper OK', ['chars' => strlen($texto)]);
                return $texto ?: null;
            }

            Log::warning('MediaProcessor Groq Whisper falhou', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 300),
            ]);
        } catch (\Exception $e) {
            Log::error('MediaProcessor transcrição exception', ['erro' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Extrai o texto puro de uma transcrição já formatada por processar()/
     * processarOficial() (ex.: "[Áudio transcrito: oi tudo bem]") — evita
     * re-transcrever o mesmo áudio (custo duplo de chamada ao Groq) só pra obter
     * o texto sem o wrapper usado no contexto do bot. Usado tanto pro Uazapi
     * quanto pra Covercut, pra ecoar a transcrição de volta na conversa.
     * Retorna null se não havia transcrição real (áudio não configurado, falha
     * ao transcrever, tipo de mídia diferente de áudio, etc.).
     */
    public function extrairTranscricaoBruta(?string $conteudoProcessado): ?string
    {
        if ($conteudoProcessado && preg_match('/^\[Áudio transcrito: (.+)\]$/su', $conteudoProcessado, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Vídeo
    // -------------------------------------------------------------------------

    private function processarVideo(array $msg, string $instanceToken): string
    {
        $caption = is_string($msg['content'] ?? null) ? ($msg['content'] ?? '') : '';
        return $caption
            ? "[Vídeo recebido com legenda: {$caption}]"
            : '[Vídeo recebido]';
    }

    // -------------------------------------------------------------------------
    // Documento
    // -------------------------------------------------------------------------

    private function processarDocumento(array $msg): string
    {
        $nomeArquivo = $msg['fileName'] ?? ($msg['filename'] ?? null);
        $caption     = is_string($msg['content'] ?? null) ? ($msg['content'] ?? '') : '';

        if ($nomeArquivo) {
            return "[Documento recebido: {$nomeArquivo}]" . ($caption ? " — {$caption}" : '');
        }

        return $caption ? "[Documento recebido: {$caption}]" : '[Documento recebido]';
    }

    // -------------------------------------------------------------------------
    // Helpers: extração de URL
    // -------------------------------------------------------------------------

    /**
     * Tenta extrair URL de mídia do payload Uazapi.
     * O campo `content` chega como objeto já decodificado pelo Laravel (array PHP)
     * na maioria dos casos — {"URL":"https://mmg.whatsapp.net/...","mimetype":"..."} —
     * mas trata também o caso de vir como string JSON, por segurança.
     * Arquivos de áudio têm URL com extensão .enc (criptografados) — devem ir via baixarMidiaDoUazapi.
     */
    private function extrairUrl(array $msg): ?string
    {
        // Campos diretos (raramente preenchidos pela Uazapi)
        foreach (['fileUrl', 'mediaUrl', 'url', 'imageUrl', 'audioUrl'] as $campo) {
            if (! empty($msg[$campo]) && str_starts_with($msg[$campo], 'http')) {
                return $msg[$campo];
            }
        }

        $content = $msg['content'] ?? null;

        if (is_string($content)) {
            // content como URL direta
            if (str_starts_with($content, 'http')) {
                return $content;
            }

            // content como string JSON: {"URL":"https://...","mimetype":"..."}
            $content = json_decode($content, true);
        }

        if (is_array($content)) {
            foreach (['URL', 'url', 'directPath', 'mediaUrl'] as $key) {
                if (! empty($content[$key]) && is_string($content[$key]) && str_starts_with($content[$key], 'http')) {
                    return $content[$key];
                }
            }
        }

        return null;
    }

    /**
     * Baixa mídia via endpoint Uazapi — necessário para arquivos criptografados (.enc).
     * Retorna ['base64' => '...', 'mime' => 'audio/ogg'] ou null.
     */
    private function baixarMidiaDoUazapi(string $instanceToken, array $msg): ?array
    {
        if (! $instanceToken || ! $this->uazapiBaseUrl) {
            return null;
        }

        $messageId = $msg['messageid'] ?? null;
        $chatId    = $msg['chatid'] ?? null;

        if (! $messageId) {
            return null;
        }

        // Mime type do content JSON para usar como fallback
        $mimeDefault = 'application/octet-stream';
        $rawContent  = $msg['content'] ?? '{}';
        $contentJson = is_array($rawContent) ? $rawContent : json_decode($rawContent, true);
        if (is_array($contentJson) && ! empty($contentJson['mimetype'])) {
            $mimeDefault = $contentJson['mimetype'];
        }

        // Tenta múltiplos endpoints de download da Uazapi
        $endpoints = [
            ['method' => 'post', 'path' => '/message/download', 'body' => ['messageId' => $messageId, 'chatId' => $chatId]],
            ['method' => 'post', 'path' => '/download',         'body' => ['messageId' => $messageId, 'chatId' => $chatId]],
            ['method' => 'get',  'path' => "/download/{$messageId}", 'body' => []],
        ];

        foreach ($endpoints as $ep) {
            try {
                $req = Http::withHeaders(['token' => $instanceToken])->timeout(20);
                $response = $ep['method'] === 'get'
                    ? $req->get("{$this->uazapiBaseUrl}{$ep['path']}")
                    : $req->post("{$this->uazapiBaseUrl}{$ep['path']}", $ep['body']);

                if ($response->successful()) {
                    $base64 = $response->json('base64') ?? $response->json('data') ?? $response->json('file');
                    $mime   = $response->json('mimetype') ?? $response->json('mimeType') ?? $mimeDefault;

                    if ($base64) {
                        return ['base64' => $base64, 'mime' => $mime];
                    }

                    // Resposta pode ser o binário direto
                    if (strlen($response->body()) > 100) {
                        return [
                            'base64' => base64_encode($response->body()),
                            'mime'   => $response->header('Content-Type') ?: $mimeDefault,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::debug('MediaProcessor endpoint falhou', ['endpoint' => $ep['path'], 'erro' => $e->getMessage()]);
            }
        }

        Log::warning('MediaProcessor: não conseguiu baixar mídia do Uazapi', ['messageId' => $messageId]);
        return null;
    }

    private function baixarUrlViaUazapi(string $instanceToken, array $msg): ?string
    {
        $midia = $this->baixarMidiaDoUazapi($instanceToken, $msg);
        if (! $midia) return null;
        return "data:{$midia['mime']};base64,{$midia['base64']}";
    }

    /**
     * Baixa e descriptografa a mídia, salvando permanentemente em storage/public
     * e retornando uma URL própria — as URLs diretas do WhatsApp (mmg.whatsapp.net)
     * expiram, então não servem pra exibir depois no histórico da conversa.
     * Retorna null se não conseguir descriptografar, nem via Uazapi, nem achar
     * uma URL direta como último recurso.
     */
    public function baixarEPersistirUrl(array $msg, string $instanceToken, string $mediaType): ?string
    {
        $descriptografado = $this->descriptografarMidiaWhatsApp($msg, $mediaType);

        if ($descriptografado) {
            return $this->salvarBytes($descriptografado['bytes'], $descriptografado['mime'] ?? '', $mediaType);
        }

        // Fallback: tenta via Uazapi (endpoint deles, hoje instável/quebrado)
        $midia = $this->baixarMidiaDoUazapi($instanceToken, $msg);
        if ($midia) {
            return $this->salvarBytes(base64_decode($midia['base64']), $midia['mime'], $mediaType);
        }

        // Último recurso: URL criptografada crua do payload (não abre no navegador,
        // mas é melhor que nada guardar caso precisemos investigar depois)
        return $this->extrairUrl($msg);
    }

    private function salvarBytes(string $bytes, string $mime, string $mediaType): string
    {
        $extensao = $this->extensaoPorMime($mime, $mediaType);
        $caminho  = 'kanban-midia/recebida-' . \Illuminate\Support\Str::random(24) . '.' . $extensao;

        \Illuminate\Support\Facades\Storage::disk('public')->put($caminho, $bytes);

        return url('storage/' . $caminho);
    }

    // -------------------------------------------------------------------------
    // Descriptografia de mídia do WhatsApp (protocolo público, sem depender do Uazapi)
    // -------------------------------------------------------------------------

    private const HKDF_INFO = [
        'image'    => 'WhatsApp Image Keys',
        'video'    => 'WhatsApp Video Keys',
        'audio'    => 'WhatsApp Audio Keys',
        'document' => 'WhatsApp Document Keys',
    ];

    /**
     * Descriptografa mídia do WhatsApp usando o `mediaKey` que já vem no payload
     * do webhook — dispensa o endpoint de download do Uazapi (instável/quebrado
     * desde 01/07). Protocolo documentado publicamente pela comunidade (usado por
     * bibliotecas como Baileys): HKDF-SHA256 sem salt expande o mediaKey (32 bytes)
     * em 112 bytes = IV(16) + chave AES-256(32) + chave HMAC(32) + refKey(32, não
     * usado aqui). O arquivo baixado é [ciphertext AES-256-CBC][HMAC-SHA256
     * truncado nos últimos 10 bytes], usado pra validar a integridade antes de
     * decifrar. Retorna null em qualquer etapa que falhar — quem chama já sabe
     * cair pro fallback do Uazapi.
     */
    private function descriptografarMidiaWhatsApp(array $msg, string $mediaType): ?array
    {
        $content = $msg['content'] ?? null;
        if (is_string($content)) {
            $content = json_decode($content, true);
        }
        if (! is_array($content)) {
            return null;
        }

        $url         = $content['URL'] ?? null;
        $mediaKeyB64 = $content['mediaKey'] ?? null;
        $mime        = $content['mimetype'] ?? null;
        $infoLabel   = self::HKDF_INFO[$mediaType] ?? null;

        if (! $url || ! $mediaKeyB64 || ! $infoLabel) {
            return null;
        }

        // A URL vem do payload do webhook (relayed pelo Uazapi a partir do próprio
        // WhatsApp) — sem essa checagem, um payload malicioso poderia fazer o
        // servidor buscar qualquer endereço interno (SSRF). Só aceita o CDN
        // oficial do WhatsApp, via HTTPS, e não segue redirecionamentos (um host
        // válido não deveria precisar redirecionar pra servir o arquivo).
        if (! $this->urlEhCdnWhatsapp($url)) {
            Log::warning('MediaProcessor: URL de mídia fora do domínio esperado do WhatsApp, recusando', ['url' => $url]);
            return null;
        }

        $mediaKey = base64_decode($mediaKeyB64, true);
        if ($mediaKey === false || strlen($mediaKey) !== 32) {
            return null;
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])->timeout(30)->get($url);
        } catch (\Exception $e) {
            Log::warning('MediaProcessor: falha ao baixar arquivo criptografado', ['erro' => $e->getMessage()]);
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $arquivo = $response->body();
        if (strlen($arquivo) <= 10) {
            return null;
        }

        $expandido = hash_hkdf('sha256', $mediaKey, 112, $infoLabel);
        $iv        = substr($expandido, 0, 16);
        $cipherKey = substr($expandido, 16, 32);
        $macKey    = substr($expandido, 48, 32);

        $ciphertext   = substr($arquivo, 0, -10);
        $macRecebido  = substr($arquivo, -10);
        $macCalculado = substr(hash_hmac('sha256', $iv . $ciphertext, $macKey, true), 0, 10);

        if (! hash_equals($macRecebido, $macCalculado)) {
            Log::warning('MediaProcessor: MAC da mídia não confere, descartando decrypt');
            return null;
        }

        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $cipherKey, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            return null;
        }

        return ['bytes' => $decrypted, 'mime' => $mime];
    }

    /**
     * Só aceita HTTPS em domínios do CDN de mídia do WhatsApp — barreira contra
     * SSRF via URL manipulada no payload do webhook.
     */
    private function urlEhCdnWhatsapp(string $url): bool
    {
        $partes = parse_url($url);
        if (! is_array($partes) || ($partes['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower($partes['host'] ?? '');

        return $host === 'whatsapp.net' || str_ends_with($host, '.whatsapp.net');
    }

    private function extensaoPorMime(string $mime, string $mediaType): string
    {
        return match (true) {
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png')  => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif')  => 'gif',
            str_contains($mime, 'ogg')  => 'ogg',
            str_contains($mime, 'mp4')  => 'mp4',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'webm') => 'webm',
            $mediaType === 'image' => 'jpg',
            $mediaType === 'audio' => 'ogg',
            default => 'bin',
        };
    }

    // -------------------------------------------------------------------------
    // Canal Oficial (Covercut) — a Meta já entrega mídia descriptografada, sem
    // precisar replicar a descriptografia E2E do WhatsApp (usada só no Uazapi).
    // -------------------------------------------------------------------------

    /**
     * Processa mensagem de mídia recebida pelo canal Oficial (Covercut) e retorna
     * texto descritivo pro contexto do bot. Cobre áudio, imagem, vídeo e
     * documento; retorna null para tipos genuinamente não suportados (ex.:
     * unsupported, sticker, poll).
     */
    public function processarOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null, bool $transcricaoAtiva = true): ?string
    {
        $tipo = $message['type'] ?? null;

        if (! $transcricaoAtiva && in_array($tipo, ['image', 'audio'], true)) {
            return $tipo === 'image'
                ? '[Imagem recebida — transcrição desativada para esta coluna]'
                : '[Áudio recebido — transcrição desativada para esta coluna]';
        }

        return match ($tipo) {
            'audio'    => $this->processarAudioOficial($message, $canal),
            'image'    => $this->processarImagemOficial($message, $canal, $focoAnalise),
            'video'    => $this->processarVideoOficial($message),
            'document' => $this->processarDocumentoOficial($message),
            default    => null,
        };
    }

    private function processarVideoOficial(array $message): string
    {
        $caption = $message['video']['caption'] ?? '';
        return $caption ? "[Vídeo recebido com legenda: {$caption}]" : '[Vídeo recebido]';
    }

    private function processarDocumentoOficial(array $message): string
    {
        $nomeArquivo = $message['document']['filename'] ?? null;
        $caption     = $message['document']['caption'] ?? '';

        if ($nomeArquivo) {
            return "[Documento recebido: {$nomeArquivo}]" . ($caption ? " — {$caption}" : '');
        }

        return $caption ? "[Documento recebido: {$caption}]" : '[Documento recebido]';
    }

    private function processarImagemOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null): string
    {
        $caption = $message['image']['caption'] ?? '';
        $mediaId = $message['image']['id'] ?? null;

        if (! $mediaId) {
            Log::warning('MediaProcessor: payload de imagem oficial sem image.id', ['message' => $message]);
            return $caption ?: '[Imagem recebida]';
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return $caption ? "[Imagem: {$caption}]" : '[Imagem recebida — não foi possível analisar o conteúdo]';
        }

        $dataUri   = 'data:' . ($midia['mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($midia['bytes']);
        $descricao = $this->descreverImagemComVisao($dataUri, $caption, $focoAnalise);
        $prefixo   = $caption ? "[Imagem: {$caption}] " : '[Imagem] ';

        return $prefixo . $descricao;
    }

    public function extrairItensImagemOficial(array $message, WhatsappCanal $canal, ?string $focoAnalise = null, bool $transcricaoAtiva = true): ?string
    {
        if (! $transcricaoAtiva || ! $this->openRouterKey) {
            return null;
        }

        $mediaId = $message['image']['id'] ?? null;
        if (! $mediaId) {
            return null;
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return null;
        }

        $dataUri = 'data:' . ($midia['mime'] ?: 'image/jpeg') . ';base64,' . base64_encode($midia['bytes']);

        return $this->chamarVisaoParaItens($dataUri, $focoAnalise);
    }

    private function processarAudioOficial(array $message, WhatsappCanal $canal): string
    {
        if (! $this->groqKey) {
            return '[Áudio recebido — transcrição não configurada]';
        }

        $mediaId = $message['audio']['id'] ?? null;
        if (! $mediaId) {
            Log::warning('MediaProcessor: payload de áudio oficial sem audio.id', ['message' => $message]);
            return '[Áudio recebido — não foi possível identificar o arquivo]';
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return '[Áudio recebido — não foi possível transcrever]';
        }

        $transcricao = $this->transcreverAudioBase64(base64_encode($midia['bytes']), $midia['mime']);

        return $transcricao
            ? "[Áudio transcrito: {$transcricao}]"
            : '[Áudio recebido — não foi possível transcrever]';
    }

    /**
     * Baixa mídia recebida pelo canal Oficial via Covercut e salva permanentemente
     * em storage/public, retornando uma URL própria (mesmo padrão de
     * baixarEPersistirUrl(), usado pelo Uazapi) — pra exibir no card do Kanban.
     * Retorna null se não conseguir baixar.
     */
    public function baixarEPersistirUrlOficial(array $message, WhatsappCanal $canal, string $mediaType): ?string
    {
        $mediaId = $message[$mediaType]['id'] ?? null;
        if (! $mediaId) {
            return null;
        }

        $midia = $this->baixarMidiaCovercut($mediaId, $canal);
        if (! $midia) {
            return null;
        }

        return $this->salvarBytes($midia['bytes'], $midia['mime'], $mediaType);
    }

    /**
     * Busca os bytes de uma mídia via API da Covercut. Usa sempre mode=stream —
     * o corpo da resposta é o arquivo bruto, mime-type no header Content-Type —
     * evita ter que adivinhar o formato de um envelope JSON/base64 não
     * documentado (ver design técnico, seção 3.1).
     * Retorna ['bytes' => string, 'mime' => string] ou null em qualquer falha.
     */
    private function baixarMidiaCovercut(string $mediaId, WhatsappCanal $canal): ?array
    {
        $baseUrl       = config('services.covercut.base_url');
        $phoneNumberId = $canal->config['phone_number_id'] ?? null;

        if (! $baseUrl || ! $phoneNumberId) {
            Log::warning('MediaProcessor: canal oficial sem base_url/phone_number_id', ['canal_id' => $canal->id]);
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key'    => config('services.covercut.api_key'),
                'X-API-Secret' => config('services.covercut.api_secret'),
            ])->timeout(30)->get("{$baseUrl}/media/get", [
                'id'   => $mediaId,
                'from' => $phoneNumberId,
                'mode' => 'stream',
            ]);

            if (! $response->successful()) {
                Log::warning('MediaProcessor: falha ao baixar mídia da Covercut', [
                    'media_id' => $mediaId,
                    'status'   => $response->status(),
                    'body'     => substr($response->body(), 0, 300),
                ]);
                return null;
            }

            $bytes = $response->body();
            $mime  = $response->header('Content-Type') ?: 'application/octet-stream';

            if ($bytes === '' || str_contains($mime, 'application/json')) {
                // A Covercut pode ignorar mode=stream e devolver o envelope JSON dela
                // (não documentado) em vez do arquivo bruto, ou simplesmente um corpo
                // vazio — nos dois casos não há bytes de mídia de verdade pra usar.
                Log::warning('MediaProcessor: resposta de mídia da Covercut vazia ou em formato inesperado', [
                    'media_id'     => $mediaId,
                    'mime'         => $mime,
                    'body_preview' => substr($bytes, 0, 300),
                ]);
                return null;
            }

            return [
                'bytes' => $bytes,
                'mime'  => $mime,
            ];
        } catch (\Throwable $e) {
            Log::error('MediaProcessor: exceção ao baixar mídia da Covercut', [
                'media_id' => $mediaId, 'erro' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
