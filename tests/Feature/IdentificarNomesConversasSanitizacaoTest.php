<?php

namespace Tests\Feature;

use App\Console\Commands\IdentificarNomesConversas;
use Tests\TestCase;

/**
 * Achado real 23/09 (Leonardo, relato de contatos "com problema no nome"):
 * confirmados 30 contatos reais em produção com o campo `nome` contaminado
 * por texto de raciocínio bruto de modelos de IA gratuitos (ex: "Here's a
 * thinking process: 1. **Analyze User Input:** - Phone number: 555...",
 * "I need to focus on analyzing the WhatsApp conversation..."), a maioria já
 * empurrada pro Google Contatos com esse "nome" (google_sincronizado_em
 * posterior a nome_revisado_ia_em). Causa raiz: contatos:identificar-nomes
 * só validava tamanho (2-100 chars) e presença de 2+ letras — modelos
 * gratuitos "de raciocínio" (reasoning) às vezes ignoram a instrução "retorne
 * APENAS o nome" e devolvem o preâmbulo de raciocínio inteiro, que passava
 * fácil por um filtro tão fraco.
 */
class IdentificarNomesConversasSanitizacaoTest extends TestCase
{
    private function valido(string $resposta): bool
    {
        return IdentificarNomesConversas::pareceNomeValido($resposta);
    }

    public function test_rejeita_preambulo_de_raciocinio_com_varias_linhas(): void
    {
        $this->assertFalse($this->valido("Here's a thinking process:\n1.  **Analyze User Input:**\n   - Phone number: 5551199\n2. Conclude: Wallace"));
    }

    public function test_rejeita_resposta_com_marcadores_de_markdown(): void
    {
        $this->assertFalse($this->valido('I need to focus on analyzing the WhatsApp conversation data to identify **Carlos**'));
    }

    public function test_rejeita_resposta_com_dois_pontos_estilo_frase_explicativa(): void
    {
        $this->assertFalse($this->valido('Telephone: 5511999998888, name found: Maria'));
    }

    public function test_rejeita_resposta_com_muitas_palavras(): void
    {
        $this->assertFalse($this->valido('The user wants me to identify names of people in WhatsApp conversations'));
    }

    public function test_rejeita_frase_com_a_palavra_here_mas_sem_pontuacao_suspeita(): void
    {
        // Garante que o filtro não vira "bloqueia qualquer coisa com 'here'"
        // ao ponto de rejeitar nomes reais que contêm esse substring por acaso.
        $this->assertTrue($this->valido('Thereza'));
    }

    public function test_aceita_nome_valido_simples(): void
    {
        $this->assertTrue($this->valido('Wallace'));
    }

    public function test_aceita_nome_composto_valido(): void
    {
        $this->assertTrue($this->valido('Maria Thereza Portugal Costa'));
    }

    public function test_aceita_nome_com_e_comercial(): void
    {
        $this->assertTrue($this->valido('Alan & Carol'));
    }

    public function test_aceita_nome_com_hifen_e_apostrofo(): void
    {
        $this->assertTrue($this->valido("Jean-Pierre D'Ávila"));
    }

    public function test_rejeita_nao_identificado(): void
    {
        $this->assertFalse($this->valido('NAO_IDENTIFICADO'));
    }

    public function test_rejeita_vazio(): void
    {
        $this->assertFalse($this->valido(''));
    }
}
