<?php

namespace App\Services;

use App\Models\PerfilGmb;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * GmbPostIaService
 * 
 * Gera postagens estratégicas e persuasivas para o Google Meu Negócio (Google Posts)
 * com base no perfil da empresa, segmento, objetivo e palavras-chave locais.
 */
class GmbPostIaService
{
    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * Gera uma sugestão completa de post (Título, Texto, CTA sugerido e Ideia de Imagem).
     */
    public function gerarPost(
        PerfilGmb $perfil,
        string $tipo = 'novidade', // novidade, oferta, evento
        ?string $objetivo = null, // atração, oferta, dúvida, prova social
        ?string $temaLivre = null,
        ?string $tomVoz = 'direto e persuasivo',
    ): ?array {
        $tenant = $perfil->tenant;
        $cidadeEstado = trim(($perfil->city ?? '') . ' ' . ($perfil->state ?? ''));

        $mensagens = [
            [
                'role' => 'system',
                'content' => "Você é um Especialista Sênior em SEO Local e Copywriting para Google Meu Negócio (Google Posts).\n"
                           . "Sua missão é criar publicações irresistíveis que aumentem as ligações, rotas e pedidos no Google Maps e na Busca do Google.\n\n"
                           . "REGRAS MANDATÓRIAS:\n"
                           . "1. O texto deve ser direto, envolvente e natural (entre 300 e 700 caracteres).\n"
                           . "2. Use emojis com moderação para tornar a leitura fluida e agradável.\n"
                           . "3. Inclua sempre 2 a 3 hashtags locais estratégicas no final (Ex: #Cidade #Bairro #Serviço).\n"
                           . "4. Termine com uma Chamada para Ação (CTA) clara convidando a clicar no botão ou entrar em contato.\n"
                           . "5. Retorne APENAS um JSON válido no formato especificado, sem blocos markdown adicionais.\n"
            ],
            [
                'role' => 'user',
                'content' => "Gere uma publicação no formato '{$tipo}' para o Google Meu Negócio com os seguintes dados:\n\n"
                           . "- Nome da Empresa: {$perfil->nome}\n"
                           . "- Nicho / Atividade: " . ($tenant?->nome ?? 'Empresa Local') . "\n"
                           . "- Localização: {$cidadeEstado}\n"
                           . "- Tipo do Post: {$tipo}\n"
                           . "- Objetivo: " . ($objetivo ?: 'Atrair clientes locais e gerar contato imediato') . "\n"
                           . ($temaLivre ? "- Instruções Específicas / Tema: {$temaLivre}\n" : "")
                           . "- Tom de Voz: {$tomVoz}\n\n"
                           . "Retorne estritamente um JSON com a seguinte estrutura:\n"
                           . "{\n"
                           . '  "titulo": "Título chamativo (obrigatório se oferta/evento, opcional para novidade)",' . "\n"
                           . '  "texto": "Corpo completo da publicação com emojis e hashtags no final",' . "\n"
                           . '  "cta_tipo": "LEARN_MORE | CALL | ORDER | BOOK | SIGN_UP",' . "\n"
                           . '  "sugestao_imagem": "Breve descrição de qual tipo de foto real postar",' . "\n"
                           . '  "dica_seo": "Dica rápida de por que este post vai ranquear bem"' . "\n"
                           . "}"
            ]
        ];

        try {
            $resposta = $this->openRouter->chat(
                $mensagens,
                'complexo',
                1200,
                'gmb_post_gerar_ia',
                $perfil->tenant_id,
            );

            if (!$resposta) {
                return null;
            }

            // Limpa formatações de markdown caso o modelo adicione ```json ... ```
            $jsonLimpo = trim($resposta);
            if (str_starts_with($jsonLimpo, '```')) {
                $jsonLimpo = preg_replace('/^```(?:json)?\s*/i', '', $jsonLimpo);
                $jsonLimpo = preg_replace('/\s*```$/', '', $jsonLimpo);
            }

            $dados = json_decode($jsonLimpo, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($dados['texto'])) {
                return $dados;
            }

            Log::warning('GmbPostIaService: JSON inválido retornado', ['resposta' => $resposta]);
            return [
                'titulo'           => $tipo !== 'novidade' ? 'Destaque da Semana' : null,
                'texto'            => $resposta,
                'cta_tipo'         => 'LEARN_MORE',
                'sugestao_imagem'  => 'Foto de alta qualidade da equipe ou produto',
                'dica_seo'         => 'Publicação gerada por IA',
            ];
        } catch (\Exception $e) {
            Log::error('GmbPostIaService exceção', ['erro' => $e->getMessage()]);
            return null;
        }
    }
}
