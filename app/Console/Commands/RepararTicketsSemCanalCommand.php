<?php

namespace App\Console\Commands;

use App\Models\Kanban;
use App\Models\TicketAtendimento;
use App\Services\SelecaoCanalWhatsappService;
use Illuminate\Console\Command;

/**
 * Repara tickets abertos que ficaram sem nenhum whatsapp_canal_id vinculado —
 * achado em 2026-08-03: o Frete Rio perdeu o canal não-oficial (Uazapi, botão
 * "Remover" em 29/07, nunca reconectado) e toda ligação perdida / formulário
 * criava ticket sem canal, porque SelecaoCanalWhatsappService::
 * naoOficialAleatorioParaKanban() só olhava canal não-oficial e retornava
 * null. O método já foi corrigido pra cair no canal oficial quando não há
 * não-oficial conectado — este comando é o backfill pontual pros tickets que
 * já ficaram travados antes da correção.
 */
class RepararTicketsSemCanalCommand extends Command
{
    protected $signature = 'whatsapp:reparar-tickets-sem-canal
                            {--dry-run : Mostra o que seria feito sem salvar}';

    protected $description = 'Vincula um canal WhatsApp conectado aos tickets abertos/aguardando que ficaram sem nenhum canal vinculado';

    public function handle(SelecaoCanalWhatsappService $selecaoCanal): int
    {
        $dryRun = $this->option('dry-run');

        $tickets = TicketAtendimento::withoutGlobalScopes()
            ->whereIn('status', ['aberto', 'aguardando'])
            ->whereNull('whatsapp_canal_id')
            ->get();

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Tickets abertos/aguardando sem canal: {$tickets->count()}");

        $corrigidos         = 0;
        $semKanbanDeVendas  = 0;
        $semCanalDisponivel = 0;

        // Cache o kanban de vendas por tenant — não precisa buscar de novo pra
        // cada ticket do mesmo tenant.
        $kanbanPorTenant = [];

        foreach ($tickets as $ticket) {
            if (! array_key_exists($ticket->tenant_id, $kanbanPorTenant)) {
                $kanbanPorTenant[$ticket->tenant_id] = Kanban::where('tenant_id', $ticket->tenant_id)
                    ->where('tipo', 'vendas')
                    ->first();
            }

            $kanban = $kanbanPorTenant[$ticket->tenant_id];

            if (! $kanban) {
                $semKanbanDeVendas++;
                continue;
            }

            $canal = $selecaoCanal->naoOficialAleatorioParaKanban($kanban);

            if (! $canal) {
                $semCanalDisponivel++;
                continue;
            }

            $this->line("Ticket #{$ticket->id} (tenant {$ticket->tenant_id}) → canal #{$canal->id} ({$canal->provider})");

            if (! $dryRun) {
                $ticket->update(['whatsapp_canal_id' => $canal->id]);
            }

            $corrigidos++;
        }

        $this->info("Corrigidos: {$corrigidos}");
        $this->info("Sem kanban de vendas configurado: {$semKanbanDeVendas}");
        $this->info("Sem nenhum canal conectado disponível: {$semCanalDisponivel}");

        return self::SUCCESS;
    }
}
