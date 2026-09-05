<?php

namespace Tests\Feature;

use App\Models\GmbPost;
use App\Models\PerfilGmb;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GmbPostControllerPublicarAgoraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private PerfilGmb $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'nome'  => 'Frete Teste',
            'slug'  => 'frete-teste',
            'nicho' => 'frete',
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'nome'      => 'Leonardo',
            'email'     => 'leo@teste.com',
            'password'  => bcrypt('senha123'),
            'perfil'    => 'admin',
            'ativo'     => true,
        ]);

        $this->perfil = PerfilGmb::create([
            'tenant_id'          => $this->tenant->id,
            'nome'               => 'Frete Rio - Tijuca',
            'city'               => 'Rio de Janeiro',
            'state'              => 'RJ',
            'link_gmb'           => 'https://maps.google.com/?cid=123',
            'google_location_id' => '16774193855414692805',
            'ativo'              => true,
        ]);
    }

    public function test_publicar_agora_exibe_mensagem_de_erro_e_grava_no_post_quando_falha(): void
    {
        session(['tenant_id' => $this->tenant->id]);

        $post = GmbPost::create([
            'tenant_id'     => $this->tenant->id,
            'perfil_gmb_id' => $this->perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'Texto de teste do post',
            'data_agendada' => now(),
            'status'        => 'agendado',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('admin.gmb-posts.publicar-agora', $post));

        $response->assertRedirect();
        $response->assertSessionHas('erro');

        $post = GmbPost::withoutGlobalScopes()->find($post->id);
        $this->assertSame('falha', $post->status);
        $this->assertNotNull($post->log_erro);
    }

    public function test_view_index_renderiza_alerta_de_erro_e_detalhe_no_card(): void
    {
        session(['tenant_id' => $this->tenant->id]);

        $post = GmbPost::create([
            'tenant_id'     => $this->tenant->id,
            'perfil_gmb_id' => $this->perfil->id,
            'tipo'          => 'novidade',
            'texto'         => 'Texto de teste do post',
            'data_agendada' => now(),
            'status'        => 'falha',
            'log_erro'      => 'Acesso não autorizado pelo Google (HTTP 403)',
        ]);

        $response = $this->actingAs($this->user)
            ->withSession([
                'tenant_id' => $this->tenant->id,
                'erro'      => 'Falha ao publicar: Acesso não autorizado',
            ])
            ->get(route('admin.gmb-posts.index'));

        if ($response->isRedirect()) {
            $this->fail('Redirected to: ' . $response->headers->get('Location'));
        }

        $response->assertOk();
        $response->assertSee('Atenção ao publicar no Google:');
        $response->assertSee('Falha ao publicar: Acesso não autorizado');
        $response->assertSee('Acesso não autorizado pelo Google (HTTP 403)');
    }
}
