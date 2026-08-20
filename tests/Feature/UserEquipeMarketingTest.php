<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserEquipeMarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_diretor_marketing_acessa_avaliacoes_gmb_mas_nao_configuracoes(): void
    {
        $nathanel = User::factory()->create(['perfil' => 'diretor_marketing']);

        $this->assertTrue($nathanel->podeAcessar('avaliacoes_gmb'));
        $this->assertTrue($nathanel->podeAcessar('campanhas'));
        $this->assertFalse($nathanel->podeAcessar('configuracoes'));
        $this->assertFalse($nathanel->podeAcessar('usuarios'));
    }

    /**
     * Achado real 2026-08-20: Nathanel também acumula a função de Gestor
     * Comercial (análise de Kanban e conversas) — precisa poder acessar o
     * Kanban e os contatos, não só GMB/campanhas.
     */
    public function test_diretor_marketing_acessa_kanban_e_contatos_pra_analise_comercial(): void
    {
        $nathanel = User::factory()->create(['perfil' => 'diretor_marketing']);

        $this->assertTrue($nathanel->podeAcessar('kanban'));
        $this->assertTrue($nathanel->podeAcessar('contatos'));
    }

    public function test_perfis_dormentes_ainda_nao_tem_nenhuma_permissao_de_recurso(): void
    {
        // Intencional: existem só como enum/perfil válido, prontos pra
        // ativação futura, mas sem escopo de trabalho definido ainda.
        foreach (['gestor_trafego', 'gestor_criacao', 'gestor_copywriting', 'gestor_seo'] as $perfil) {
            $user = User::factory()->create(['perfil' => $perfil]);
            $this->assertFalse($user->podeAcessar('configuracoes'), "{$perfil} não deveria acessar configuracoes");
            $this->assertFalse($user->podeAcessar('usuarios'), "{$perfil} não deveria acessar usuarios");
        }
    }

    public function test_perfis_da_equipe_marketing_tem_labels_proprios(): void
    {
        $casos = [
            'diretor_marketing'  => 'Diretora de Marketing',
            'gestor_trafego'     => 'Gestor de Tráfego',
            'gestor_criacao'     => 'Gestor de Criação',
            'gestor_copywriting' => 'Gestor de Copywriting',
            'gestor_seo'         => 'Gestor de SEO',
        ];

        foreach ($casos as $perfil => $labelEsperado) {
            $user = User::factory()->create(['perfil' => $perfil]);
            $this->assertSame($labelEsperado, $user->perfilLabel());
        }
    }

    public function test_isequipemarketing_nao_inclui_perfis_normais(): void
    {
        $dono  = User::factory()->create(['perfil' => 'dono']);
        $admin = User::factory()->create(['perfil' => 'admin']);

        $this->assertFalse($dono->isEquipeMarketing());
        $this->assertFalse($admin->isEquipeMarketing());
        $this->assertTrue($admin->podeTrocarTenant());
        $this->assertFalse($dono->podeTrocarTenant());
    }
}
