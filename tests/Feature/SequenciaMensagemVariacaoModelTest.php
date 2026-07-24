<?php

namespace Tests\Feature;

use App\Models\Sequencia;
use App\Models\SequenciaMensagem;
use App\Models\SequenciaMensagemVariacao;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SequenciaMensagemVariacaoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_mensagem_tem_relacao_variacoes(): void
    {
        $tenant    = Tenant::factory()->create();
        $sequencia = Sequencia::create([
            'tenant_id' => $tenant->id, 'nome' => 'Boas-vindas', 'coluna_kanban' => 'lead_novo', 'ativo' => true,
        ]);
        $msg = SequenciaMensagem::create([
            'tenant_id' => $tenant->id, 'sequencia_id' => $sequencia->id, 'ordem' => 1,
            'conteudo' => 'Olá {nome}!', 'delay_segundos' => 0, 'ativo' => true,
        ]);

        $variacao = SequenciaMensagemVariacao::create([
            'tenant_id'             => $tenant->id,
            'sequencia_mensagem_id' => $msg->id,
            'conteudo'              => 'Olá {nome}!',
            'origem'                => 'humano',
            'protegida'             => true,
            'ativa'                 => true,
        ]);

        $this->assertCount(1, $msg->variacoes);
        $this->assertTrue($msg->variacoes->first()->is($variacao));
        $this->assertTrue($variacao->protegida);
        $this->assertTrue($variacao->ativa);
        $this->assertSame('humano', $variacao->origem);
    }
}
