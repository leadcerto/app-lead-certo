<?php

namespace Tests\Feature;

use App\Models\Contato;
use App\Models\ContatoPendente;
use App\Services\ContatoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Achado real 2026-09-16/17 (ticket #4788, contato #93802): o sync periódico
 * do Google (ContatoSyncService::processarPessoa) já detecta "número
 * possivelmente reciclado" comparando similaridade de nome — mas o caminho
 * de MENSAGEM NOVA do WhatsApp (CovercutWebhookController/
 * UazapiWebhookController, que resolvem contato só por telefone) nunca
 * tinha essa proteção. Foi assim que "Daniela" (contato antigo, dono
 * anterior do número) grudou no "Rodrigo" (dono atual) sem nenhum alerta.
 *
 * Este teste cobre o novo método compartilhado que os dois webhooks passam
 * a chamar — mesma regra de similaridade (LIMIAR_SIMILARIDADE = 75%), sem
 * bloquear a conversa: só registra em contatos_pendentes pra revisão.
 */
class ContatoSyncServiceNumeroRecicladoWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_conflito_quando_nome_novo_nao_bate_com_o_existente(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5511999990001', 'nome' => 'Daniela']);

        app(ContatoSyncService::class)->flagrarSeNumeroPossivelmenteReciclado(
            $contato, tenantId: 1, nomeNovo: 'Rodrigo Dalmeida', telefone: '5511999990001',
        );

        $this->assertDatabaseHas('contatos_pendentes', [
            'telefone'             => '5511999990001',
            'contato_existente_id' => $contato->id,
            'tipo_conflito'        => 'numero_possivelmente_reciclado',
            'nome_existente'       => 'Daniela',
            'nome'                 => 'Rodrigo Dalmeida',
            'status'               => 'aguardando',
        ]);
    }

    public function test_nao_registra_quando_nome_novo_e_parecido_com_o_existente(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5511999990002', 'nome' => 'Rodrigo Dalmeida']);

        app(ContatoSyncService::class)->flagrarSeNumeroPossivelmenteReciclado(
            $contato, tenantId: 1, nomeNovo: 'Rodrigo Dalmeida', telefone: '5511999990002',
        );

        $this->assertDatabaseCount('contatos_pendentes', 0);
    }

    public function test_nao_registra_quando_contato_existente_ainda_nao_tem_nome_real(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5511999990003', 'nome' => 'Sem Nome']);

        app(ContatoSyncService::class)->flagrarSeNumeroPossivelmenteReciclado(
            $contato, tenantId: 1, nomeNovo: 'Rodrigo Dalmeida', telefone: '5511999990003',
        );

        $this->assertDatabaseCount('contatos_pendentes', 0);
    }

    public function test_nao_registra_quando_nome_novo_esta_vazio(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5511999990004', 'nome' => 'Daniela']);

        app(ContatoSyncService::class)->flagrarSeNumeroPossivelmenteReciclado(
            $contato, tenantId: 1, nomeNovo: null, telefone: '5511999990004',
        );

        $this->assertDatabaseCount('contatos_pendentes', 0);
    }

    public function test_nao_duplica_registro_pro_mesmo_conflito(): void
    {
        $contato = Contato::factory()->create(['telefone' => '5511999990005', 'nome' => 'Daniela']);

        $service = app(ContatoSyncService::class);
        $service->flagrarSeNumeroPossivelmenteReciclado($contato, 1, 'Rodrigo Dalmeida', '5511999990005');
        $service->flagrarSeNumeroPossivelmenteReciclado($contato, 1, 'Rodrigo Dalmeida', '5511999990005');

        $this->assertDatabaseCount('contatos_pendentes', 1);
    }
}
