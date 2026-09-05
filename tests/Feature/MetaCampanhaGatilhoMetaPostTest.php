<?php

namespace Tests\Feature;

use App\Models\MetaCampanhaGatilho;
use App\Models\MetaPost;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaCampanhaGatilhoMetaPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_relaciona_gatilho_ao_meta_post_de_origem(): void
    {
        $tenant = Tenant::factory()->create();
        session(['tenant_id' => $tenant->id]);

        $post = MetaPost::create([
            'tenant_id'     => $tenant->id,
            'canal_alvo'    => 'facebook',
            'texto'         => 'x',
            'data_agendada' => now(),
            'status'        => 'publicado',
        ]);

        $gatilho = MetaCampanhaGatilho::create([
            'tenant_id'       => $tenant->id,
            'meta_post_id'    => $post->id,
            'nome'            => 'Gatilho auto',
            'canal_alvo'      => 'facebook',
            'modo_gatilho'    => 'qualquer_comentario',
            'mensagem_direct' => 'Olá!',
            'ativo'           => true,
        ]);

        $this->assertTrue($gatilho->metaPost->is($post));
    }
}
