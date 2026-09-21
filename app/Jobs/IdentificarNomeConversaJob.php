<?php

namespace App\Jobs;

use App\Models\Contato;
use App\Models\KanbanColunaObjetivo;
use App\Models\Mensagem;
use App\Models\TicketAtendimento;
use App\Services\AvancoAutomaticoKanbanService;
use App\Services\OpenRouterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Achado real (2026-08-14): quando o lead se identifica pelo próprio nome
 * dentro da conversa (texto ou áudio transcrito), sem o bot ter perguntado
 * diretamente, nada capturava isso — o contato ficava com o telefone como
 * nome (placeholder criado em SecretariaEletronicaController/webhooks) até
 * alguém corrigir manualmente. Despachado pelo hook único em
 * Mensagem::booted() sempre que chega mensagem do lead e o nome do contato
 * ainda parece inválido (Contato::semNomeReal()).
 */
class IdentificarNomeConversaJob implements ShouldQueue
{
    use Queueable;

    private const PROMPT_SISTEMA = <<<'PROMPT'
Você analisa uma mensagem (ou transcrição de áudio) de alguém que entrou em
contato com uma empresa de fretes/mudanças. Verifique se a pessoa disse, se
apresentou ou informou o PRÓPRIO nome (ex: "meu nome é João", "aqui é a Maria",
"sou o Carlos", ou respondendo diretamente apenas o próprio nome como "Wallace" ou "Leonardo").
Se a pessoa informou o nome, responda SOMENTE com o nome da pessoa
(primeiro nome + sobrenome se houver), sem emoji, sem cargo, sem
nome de empresa, sem nenhum texto extra. Se a pessoa não informou o próprio
nome, ou se o texto for propaganda/venda/dúvida/endereço sem identificação,
responda exatamente a palavra NENHUM.
PROMPT;

    public function __construct(public int $mensagemId) {}

    public function handle(OpenRouterService $openRouter, AvancoAutomaticoKanbanService $avanco): void
    {
        $mensagem = Mensagem::withoutGlobalScopes()->find($this->mensagemId);
        if (! $mensagem || ! $mensagem->conteudo) {
            return;
        }

        $ticket = TicketAtendimento::withoutGlobalScopes()->find($mensagem->ticket_id);
        if (! $ticket) {
            return;
        }

        $contato = Contato::find($ticket->contato_id);
        if (! $contato || ! $contato->semNomeReal()) {
            return; // já foi resolvido por outra mensagem, ou editado manualmente nesse meio tempo
        }

        $resposta = $openRouter->chat([
            ['role' => 'system', 'content' => self::PROMPT_SISTEMA],
            ['role' => 'user', 'content' => $mensagem->conteudo],
        ], 'simples', 60, 'identificar_nome_conversa', $ticket->tenant_id);

        $nome = $this->validarNome($resposta);
        if (! $nome) {
            return;
        }

        $contato->update(['nome' => $nome, 'nome_revisado_ia_em' => now()]);

        // Invalida google_sincronizado_em do vínculo para o Atlas atualizar no Google no próximo ciclo
        \App\Models\VinculoContatoTenant::where('contato_id', $contato->id)
            ->update(['google_sincronizado_em' => null]);

        $this->marcarObjetivoDeNomeSePendente($ticket, $avanco);
    }

    /**
     * Achado real 2026-09-21 (ticket #4816): o avanço automático de coluna
     * dependia inteiramente da própria IA lembrar de colar uma tag no texto
     * livre — sem nenhuma verificação de apoio, ela pode simplesmente
     * esquecer (confirmado com evidência real: 3 chamadas de IA seguidas no
     * mesmo ticket, todas esquecendo a tag). Este job é o único ponto do
     * sistema que sabe, de forma determinística — sem depender de nenhuma
     * IA — exatamente o instante em que um nome real foi capturado. Marca
     * o objetivo correspondente (se a coluna atual tiver um) como rede de
     * segurança, reaproveitando o mesmo AvancoAutomaticoKanbanService que já
     * avança a coluna sozinho quando a checklist fecha.
     */
    private function marcarObjetivoDeNomeSePendente(TicketAtendimento $ticket, AvancoAutomaticoKanbanService $avanco): void
    {
        $idObjetivoNome = KanbanColunaObjetivo::withoutGlobalScopes()
            ->where('tenant_id', $ticket->tenant_id)
            ->where('coluna_kanban', $ticket->coluna_kanban)
            ->where('ativo', true)
            ->where('texto', 'like', '%nome%')
            ->value('id');

        if ($idObjetivoNome) {
            $avanco->marcarObjetivos($ticket, [$idObjetivoNome]);
        }
    }

    /**
     * Defesa extra além do prompt — nunca aceita algo com cara de emoji,
     * frase longa tipo relatório, ou nome de empresa em vez de pessoa.
     * Pedido explícito do Leonardo (2026-08-14).
     */
    private function validarNome(?string $resposta): ?string
    {
        if (! $resposta) {
            return null;
        }

        $nome = trim($resposta);

        if ($nome === '' || mb_strtoupper($nome) === 'NENHUM') {
            return null;
        }

        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $nome)) {
            return null;
        }

        if (mb_strlen($nome) > 60 || str_word_count($nome) > 5) {
            return null;
        }

        if (preg_match('/\b(empresa|companhia|ltda|s\.?a\.?|mei)\b/iu', $nome)) {
            return null;
        }

        return $nome;
    }
}
