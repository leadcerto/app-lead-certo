<?php

namespace Tests\Feature;

use App\Jobs\SequenciaMensagemJob;
use App\Models\ChamadaPerdida;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Achado real (2026-08-12): quando a primeira tentativa de mandar a mensagem
 * de abertura falhava (ex: janela do WhatsApp fechada), o ticket ficava aberto
 * sem nenhuma mensagem — e como o sistema só checava "já existe ticket aberto?"
 * (sem olhar a data), esse número nunca mais recebia mensagem nenhuma, pra
 * sempre (caso real: ticket #2664, 11 dias parado). Regra combinada com o
 * Leonardo: só NÃO reenvia se a ligação anterior desse número foi no MESMO
 * DIA — em dia diferente, tenta mandar de novo mesmo reaproveitando o ticket.
 */
class SecretariaEletronicaReenvioMesmoDiaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_segunda_ligacao_no_mesmo_dia_nao_reenvia_mensagem(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

        Tenant::factory()->create([
            'secretaria_token' => 'token-mesmo-dia', 'secretaria_envio_ativo' => true,
        ]);

        $this->postJson('/api/secretaria/token-mesmo-dia', [
            'numero_chamador' => '11999995555', 'duracao_segundos' => 0,
        ])->assertOk()->assertJsonFragment(['acao' => 'mensagem_enviada']);

        Carbon::setTestNow(Carbon::parse('2026-08-12 15:00:00'));

        $response = $this->postJson('/api/secretaria/token-mesmo-dia', [
            'numero_chamador' => '11999995555', 'duracao_segundos' => 0,
        ]);

        $response->assertOk()->assertJsonFragment(['acao' => 'ja_ligou_hoje']);
        Queue::assertPushed(SequenciaMensagemJob::class, 1);

        // Fila fake — o job (assíncrono) nunca roda de verdade, então nenhuma das
        // duas chamadas foi marcada como enviada ainda. O desfecho real só chega
        // quando o job roda e reporta de volta via `chamadaPerdidaId`
        // (SequenciaMensagemJobChamadaPerdidaTest cobre esse caminho).
        $chamadas = ChamadaPerdida::where('numero_chamador', '5511999995555')->orderBy('chamou_em')->get();
        $this->assertFalse($chamadas[0]->mensagem_enviada);
        $this->assertFalse($chamadas[1]->mensagem_enviada);
    }

    public function test_ligacao_em_dia_diferente_reenvia_mesmo_com_ticket_ja_existente(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00'));

        Tenant::factory()->create([
            'secretaria_token' => 'token-dia-diferente', 'secretaria_envio_ativo' => true,
        ]);

        // Primeira ligação — cria o ticket, dispara a sequência (caso real: essa
        // tentativa pode falhar do lado da Meta, mas o ticket já fica registrado).
        $this->postJson('/api/secretaria/token-dia-diferente', [
            'numero_chamador' => '11999996666', 'duracao_segundos' => 0,
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

        $response = $this->postJson('/api/secretaria/token-dia-diferente', [
            'numero_chamador' => '11999996666', 'duracao_segundos' => 0,
        ]);

        $response->assertOk()->assertJsonFragment(['acao' => 'mensagem_enviada']);
        Queue::assertPushed(SequenciaMensagemJob::class, 2);

        // Fila fake — nenhum dos dois jobs rodou de verdade ainda, então
        // `mensagem_enviada` permanece false pra ambos (ver comentário no teste
        // acima). O que este teste garante é a parte de dedup: duas ligações em
        // dias diferentes disparam dois jobs, sem duplicar o ticket.
        $chamadas = ChamadaPerdida::where('numero_chamador', '5511999996666')->orderBy('chamou_em')->get();
        $this->assertFalse($chamadas[0]->mensagem_enviada);
        $this->assertFalse($chamadas[1]->mensagem_enviada);

        // As duas chamadas apontam pro mesmo ticket — não duplicou.
        $this->assertSame($chamadas[0]->ticket_id, $chamadas[1]->ticket_id);
    }

    public function test_terceira_ligacao_no_mesmo_dia_da_segunda_nao_reenvia(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00'));

        Tenant::factory()->create([
            'secretaria_token' => 'token-terceira', 'secretaria_envio_ativo' => true,
        ]);

        $this->postJson('/api/secretaria/token-terceira', [
            'numero_chamador' => '11999997777', 'duracao_segundos' => 0,
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));
        $this->postJson('/api/secretaria/token-terceira', [
            'numero_chamador' => '11999997777', 'duracao_segundos' => 0,
        ])->assertOk()->assertJsonFragment(['acao' => 'mensagem_enviada']);

        Carbon::setTestNow(Carbon::parse('2026-08-12 16:00:00'));
        $this->postJson('/api/secretaria/token-terceira', [
            'numero_chamador' => '11999997777', 'duracao_segundos' => 0,
        ])->assertOk()->assertJsonFragment(['acao' => 'ja_ligou_hoje']);

        Queue::assertPushed(SequenciaMensagemJob::class, 2);
    }
}
