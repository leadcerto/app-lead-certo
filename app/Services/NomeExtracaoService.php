<?php

namespace App\Services;

/**
 * Extrai o nome da pessoa mencionado dentro de uma mensagem de texto (ou
 * transcrição de áudio) — cobre padrões comuns em português como "meu nome
 * é X", "aqui é X", "sou X". Compartilhado entre UazapiWebhookController e
 * CovercutWebhookController (regra de paridade entre canais do CLAUDE.md).
 *
 * Achado real (2026-08-14): essa lógica viveu só do lado Uazapi por muito
 * tempo — um lead que ligou (Secretária Eletrônica) e se identificou por
 * áudio no canal Oficial nunca teve o nome capturado, porque nunca tinha
 * sido portada pro Covercut. Extraída pra cá pra existir num lugar só.
 */
class NomeExtracaoService
{
    // Verbos, saudações e termos de negócio que NUNCA são primeiros nomes
    private const NAO_NOMES = [
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

    /**
     * Tenta extrair o primeiro nome mencionado em uma mensagem de texto ou
     * transcrição. Retorna null se nenhum padrão for encontrado.
     */
    public function extrairDaMensagem(string $texto): ?string
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

        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $texto, $m)) {
                $nome         = trim($m[1]);
                $primeiraWord = mb_strtolower(explode(' ', $nome)[0], 'UTF-8');
                if (in_array($primeiraWord, self::NAO_NOMES, true) || in_array(mb_strtolower($nome, 'UTF-8'), self::NAO_NOMES, true)) {
                    continue;
                }
                return mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return null;
    }
}
