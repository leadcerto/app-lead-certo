<?php

namespace Tests\Feature;

use App\Models\Contato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 2026-09-29 (Leonardo): pedido de aplicar a nova regra
 * "pessoa sempre tem prioridade no campo nome, empresa vira sobrenome" em
 * toda a base — não só nos conflitos novos. Contatos JÁ revisados pela IA
 * (nome_revisado_ia_em preenchido) com um nome vindo de outro caminho
 * (fusão de conflito de identidade, edição manual, sync do Google) nunca
 * mais entrariam na fila de `contatos:limpar-nomes` pra pegar a regra nova,
 * porque a query do comando pula quem já tem nome_revisado_ia_em preenchido.
 * Contato::booted() agora zera nome_revisado_ia_em sempre que o campo nome
 * muda por um caminho que NÃO é o próprio classificador (que sempre grava
 * nome_revisado_ia_em na mesma chamada) — assim o próximo ciclo agendado
 * revisa o nome de novo com a regra atual.
 */
class ContatoRevisaoIaResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_mudar_nome_fora_do_classificador_zera_revisao_ia(): void
    {
        $contato = Contato::factory()->create([
            'nome'                => 'Muay',
            'nome_revisado_ia_em' => now(),
        ]);

        $contato->update(['nome' => 'Muay Thai Equipamentos']);

        $this->assertNull($contato->fresh()->nome_revisado_ia_em);
    }

    public function test_classificador_gravando_nome_e_revisao_juntos_nao_e_zerado(): void
    {
        $contato = Contato::factory()->create([
            'nome'                => 'Muay',
            'nome_revisado_ia_em' => now()->subDay(),
        ]);

        $contato->update(['nome' => 'Sem Nome', 'sobrenome' => 'Muay Thai Equipamentos', 'nome_revisado_ia_em' => now()]);

        $this->assertNotNull($contato->fresh()->nome_revisado_ia_em);
    }

    public function test_mudar_outro_campo_sem_mexer_no_nome_nao_zera_revisao_ia(): void
    {
        $contato = Contato::factory()->create([
            'nome'                => 'Muay Thai Equipamentos',
            'nome_revisado_ia_em' => now(),
        ]);

        $contato->update(['email' => 'novo@exemplo.com']);

        $this->assertNotNull($contato->fresh()->nome_revisado_ia_em);
    }
}
