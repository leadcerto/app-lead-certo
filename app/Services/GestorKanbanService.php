<?php

namespace App\Services;

use App\Models\GestorKanbanConfig;
use App\Models\GestorKanbanRelatorio;
use App\Models\KanbanColunaHistorico;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GestorKanbanService
{
    private const COLUNAS = [
        'lead_novo', 'em_atendimento', 'aguardando_orcamento', 'aguardando_lead',
        'pagamento', 'servico_agendado', 'encerrado', 'outros',
    ];

    public function __construct(private OpenRouterService $openRouter) {}

    public function coletarNumerosColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim): array
    {
        $entradas = KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna', $coluna)
            ->whereBetween('entrou_em', [$inicio, $fim])
            ->count();

        $avancos = KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_anterior', $coluna)
            ->whereBetween('entrou_em', [$inicio, $fim])
            ->count();

        $travados = $this->travadosNaColuna($tenant, $coluna, $inicio);

        $tagDesfechoBreakdown = [];
        if ($coluna === 'encerrado') {
            $tagDesfechoBreakdown = TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('coluna_kanban', 'encerrado')
                ->whereBetween('encerrado_em', [$inicio, $fim])
                ->whereNotNull('tag_desfecho')
                ->selectRaw('tag_desfecho, count(*) as total')
                ->groupBy('tag_desfecho')
                ->pluck('total', 'tag_desfecho')
                ->toArray();
        }

        return [
            'entradas'               => $entradas,
            'avancos'                => $avancos,
            'travados'               => $travados,
            'tag_desfecho_breakdown' => $tagDesfechoBreakdown,
        ];
    }

    public function amostrarConversasColuna(Tenant $tenant, string $coluna, Carbon $inicio, Carbon $fim, int $limite = 15): Collection
    {
        if ($coluna !== 'encerrado') {
            return TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('coluna_kanban', $coluna)
                ->with('mensagens')
                ->orderBy('updated_at', 'asc')
                ->limit($limite)
                ->get();
        }

        // Coluna "encerrado": os fechados NESTA semana são o dado que o
        // relatório semanal realmente precisa mostrar, então entram primeiro
        // e sempre cabem (até $limite). Só preenche o que sobrar com os mais
        // travados (fechados há mais tempo) — nunca o contrário, senão um
        // tenant com muito histórico antigo faz o volume velho engolir a
        // amostra e nenhum encerramento da semana aparece na análise.
        $fechados = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'encerrado')
            ->whereBetween('encerrado_em', [$inicio, $fim])
            ->with('mensagens')
            ->orderByDesc('encerrado_em')
            ->limit($limite)
            ->get();

        $vagasRestantes = $limite - $fechados->count();

        if ($vagasRestantes <= 0) {
            return $fechados->values();
        }

        $travados = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', 'encerrado')
            ->whereNotIn('id', $fechados->pluck('id'))
            ->with('mensagens')
            ->orderBy('updated_at', 'asc')
            ->limit($vagasRestantes)
            ->get();

        return $fechados->merge($travados)->values();
    }

    public function formatarConversa(TicketAtendimento $ticket): string
    {
        if ($ticket->coluna_kanban === 'encerrado' && $ticket->resumo_ia) {
            return "[Resumo] {$ticket->resumo_ia}";
        }

        return $ticket->mensagens
            ->filter(fn ($m) => $m->conteudo && $m->conteudo !== '')
            ->map(fn ($m) => match ($m->remetente) {
                'lead'   => 'CLIENTE: ' . $m->conteudo,
                'bot'    => 'BOT: ' . $m->conteudo,
                'humano' => 'ATENDENTE: ' . $m->conteudo,
                default  => strtoupper($m->remetente) . ': ' . $m->conteudo,
            })
            ->implode("\n");
    }

    public function analisarColuna(Tenant $tenant, string $coluna, array $numeros, Collection $amostras, GestorKanbanConfig $config): array
    {
        $conversas = $amostras
            ->map(fn (TicketAtendimento $t) => $this->formatarConversa($t))
            ->filter(fn (string $texto) => trim($texto) !== '')
            ->implode("\n\n---\n\n");

        $numerosTexto = "Entradas: {$numeros['entradas']} | Avanços: {$numeros['avancos']} | Travados: {$numeros['travados']}";
        if (! empty($numeros['tag_desfecho_breakdown'])) {
            $breakdown = collect($numeros['tag_desfecho_breakdown'])
                ->map(fn ($total, $tag) => "{$tag}: {$total}")
                ->implode(', ');
            $numerosTexto .= "\nMotivos de encerramento: {$breakdown}";
        }

        $resposta = $this->openRouter->chat([
            ['role' => 'system', 'content' => $config->prompt_coluna],
            ['role' => 'user', 'content' => "Coluna: {$coluna}\n\nNúmeros da semana:\n{$numerosTexto}\n\nAmostra de conversas:\n\n{$conversas}"],
        ], 'complexo', 800, 'gestor_kanban_coluna', $tenant->id);

        if (! $resposta) {
            Log::warning('GestorKanbanService: falha ao analisar coluna', ['tenant_id' => $tenant->id, 'coluna' => $coluna]);
            return ['analise' => null, 'sugestao_prompt' => null];
        }

        return [
            'analise'         => $this->extrairAnalise($resposta),
            'sugestao_prompt' => $this->extrairSugestao($resposta),
        ];
    }

    private function extrairAnalise(string $resposta): ?string
    {
        $semSugestao = preg_split('/SUGEST[AÃ]O_PROMPT:/iu', $resposta)[0];
        $analise     = trim(preg_replace('/AN[AÁ]LISE:\s*/iu', '', $semSugestao, 1));

        return $analise !== '' ? $analise : null;
    }

    private function extrairSugestao(string $resposta): ?string
    {
        if (! preg_match('/SUGEST[AÃ]O_PROMPT:\s*(.+)/isu', $resposta, $m)) {
            return null;
        }

        $sugestao = trim($m[1]);

        return $sugestao !== '' ? $sugestao : null;
    }

    /**
     * Tickets que estão atualmente na coluna e entraram nela antes do início
     * da semana analisada — ou seja, já estavam parados ali a semana inteira.
     */
    private function travadosNaColuna(Tenant $tenant, string $coluna, Carbon $inicioSemana): int
    {
        $ticketIds = TicketAtendimento::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('coluna_kanban', $coluna)
            ->pluck('id');

        if ($ticketIds->isEmpty()) {
            return 0;
        }

        return KanbanColunaHistorico::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('ticket_id', $ticketIds)
            ->selectRaw('ticket_id, MAX(entrou_em) as ultima_entrada')
            ->groupBy('ticket_id')
            ->havingRaw('MAX(entrou_em) < ?', [$inicioSemana])
            ->get()
            ->count();
    }

    public function gerarRelatorioSemanal(Tenant $tenant, Carbon $inicio, Carbon $fim): ?GestorKanbanRelatorio
    {
        $config = GestorKanbanConfig::first();

        if (! $config) {
            Log::error('GestorKanbanService: config global não encontrada');
            return null;
        }

        $dados        = [];
        $temAtividade = false;

        foreach (self::COLUNAS as $coluna) {
            $numeros = $this->coletarNumerosColuna($tenant, $coluna, $inicio, $fim);

            if ($numeros['entradas'] === 0 && $numeros['avancos'] === 0 && $numeros['travados'] === 0) {
                $dados[$coluna] = array_merge($numeros, [
                    'coluna'          => $coluna,
                    'analise'         => 'Sem atividade nesta coluna na semana.',
                    'sugestao_prompt' => null,
                ]);
                continue;
            }

            $temAtividade = true;
            $amostras      = $this->amostrarConversasColuna($tenant, $coluna, $inicio, $fim);
            $resultado     = $this->analisarColuna($tenant, $coluna, $numeros, $amostras, $config);

            $dados[$coluna] = array_merge($numeros, ['coluna' => $coluna], $resultado);
        }

        if (! $temAtividade) {
            return null;
        }

        $sintese = $this->sintetizarSemana($tenant, $dados, $config);

        return GestorKanbanRelatorio::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'semana_inicio' => $inicio->copy()->startOfDay()],
            [
                'semana_fim'    => $fim->toDateString(),
                'dados'         => $dados,
                'sintese_geral' => $sintese ? trim($sintese) : null,
            ]
        );
    }

    public function sintetizarSemana(Tenant $tenant, array $dadosPorColuna, GestorKanbanConfig $config): ?string
    {
        $resumoColunas = collect($dadosPorColuna)
            ->filter(fn (array $d) => ! empty($d['analise']))
            ->map(fn (array $d, string $coluna) => "### {$coluna}\n{$d['analise']}")
            ->implode("\n\n");

        if (trim($resumoColunas) === '') {
            return null;
        }

        return $this->openRouter->chat([
            ['role' => 'system', 'content' => $config->prompt_sintese],
            ['role' => 'user', 'content' => "Análises da semana por coluna:\n\n{$resumoColunas}"],
        ], 'complexo', 600, 'gestor_kanban_sintese', $tenant->id);
    }
}
