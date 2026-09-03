<?php

namespace Tests\Feature;

use App\Jobs\ProcessarCommentToDmJob;
use App\Models\MetaCampanhaGatilho;
use App\Models\MetaContaInstagram;
use App\Models\MetaPagina;
use App\Models\MetaToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_verificacao_meta_valida_challenge(): void
    {
        config(['services.meta.webhook_verify_token' => 'teste_verify_123']);

        $response = $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=teste_verify_123&hub_challenge=DESAFIO_12345');

        $response->assertStatus(200);
        $this->assertSame('DESAFIO_12345', $response->getContent());
    }

    public function test_webhook_recebe_comentario_instagram_e_despacha_job(): void
    {
        Queue::fake();

        $payload = [
            'object' => 'instagram',
            'entry'  => [
                [
                    'id'      => '1784140001',
                    'changes' => [
                        [
                            'field' => 'comments',
                            'value' => [
                                'id'    => 'comment_999',
                                'text'  => 'Eu quero saber mais sobre o frete na Barra',
                                'media' => ['id' => 'media_777'],
                                'from'  => [
                                    'id'       => 'user_555',
                                    'username' => 'cliente_vip',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/api/webhooks/meta', $payload);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessarCommentToDmJob::class, function ($job) {
            return $job->commentId === 'comment_999'
                && $job->textoComentario === 'Eu quero saber mais sobre o frete na Barra'
                && $job->fromUsername === 'cliente_vip'
                && $job->plataforma === 'instagram';
        });
    }

    public function test_campanha_gatilho_satisfaz_palavra_chave(): void
    {
        $tenant = Tenant::create([
            'nome'  => 'Frete Rio Teste',
            'nicho' => 'frete_mudanca',
        ]);

        $gatilho = MetaCampanhaGatilho::create([
            'tenant_id'                   => $tenant->id,
            'nome'                        => 'Teste Quero',
            'canal_alvo'                  => 'instagram',
            'modo_gatilho'                => 'palavra_chave',
            'palavras_chave'              => ['quero', 'saiba mais', 'saber mais'],
            'mensagem_direct'             => 'Olá! Aqui está o link.',
            'ativo'                       => true,
        ]);

        $this->assertTrue($gatilho->satisfazGatilho('Olá, eu QUERO receber a tabela!'));
        $this->assertTrue($gatilho->satisfazGatilho('Gostaria de saber mais por favor'));
        $this->assertFalse($gatilho->satisfazGatilho('Apenas um elogio ao post'));
    }
}
