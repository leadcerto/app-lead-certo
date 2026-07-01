<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgendaImediataService
{
    public function getAgenda(User $user): array
    {
        $tenantId  = $user->tenant_id;
        $threshold = now()->subMinutes(15);

        // Last lead message time per ticket
        $latestLead = DB::table('mensagens')
            ->selectRaw('ticket_id, MAX(enviado_em) as ultima_lead_em')
            ->where('remetente', 'lead')
            ->groupBy('ticket_id');

        // Last human/bot response time per ticket
        $latestHuman = DB::table('mensagens')
            ->selectRaw('ticket_id, MAX(enviado_em) as ultima_humano_em')
            ->whereIn('remetente', ['humano', 'bot'])
            ->groupBy('ticket_id');

        // Tickets where human has not replied to the lead's last message in >15min
        $espera = DB::table('tickets_atendimento as t')
            ->select('t.id', 't.contato_id', 'lm.ultima_lead_em')
            ->joinSub($latestLead, 'lm', 'lm.ticket_id', '=', 't.id')
            ->leftJoinSub($latestHuman, 'hm', 'hm.ticket_id', '=', 't.id')
            ->where('t.tenant_id', $tenantId)
            ->where('t.coluna_kanban', '!=', 'encerrado')
            ->where('t.agente_responsavel', 'humano')
            ->where('lm.ultima_lead_em', '<=', $threshold)
            ->where(function ($q) {
                $q->whereNull('hm.ultima_humano_em')
                  ->orWhereColumn('hm.ultima_humano_em', '<', 'lm.ultima_lead_em');
            })
            ->get();

        $contatoIds = $espera->pluck('contato_id')->filter()->unique()->values();
        $contatos   = $contatoIds->isNotEmpty()
            ? DB::table('contatos')->whereIn('id', $contatoIds)->pluck('telefone', 'id')
            : collect();

        $urgentes = $espera->map(function ($row) use ($contatos) {
            $minutos  = (int) \Carbon\Carbon::parse($row->ultima_lead_em)->diffInMinutes(now());
            $telefone = $contatos->get($row->contato_id, '#' . $row->id);
            return [
                'id'        => 'ticket_' . $row->id,
                'titulo'    => $telefone,
                'descricao' => "aguardando resposta há {$minutos} min",
                'url'       => '/kanban',
            ];
        })->values()->all();

        // New leads without assignment
        $totalNovos = DB::table('tickets_atendimento')
            ->where('tenant_id', $tenantId)
            ->where('coluna_kanban', 'lead_novo')
            ->count();

        $hoje = [];
        if ($totalNovos > 0) {
            $hoje[] = [
                'id'        => 'lead_novos',
                'titulo'    => $totalNovos . ($totalNovos === 1 ? ' lead novo' : ' leads novos'),
                'descricao' => 'sem atribuição no kanban',
                'url'       => '/kanban',
            ];
        }

        return [
            'urgentes' => $urgentes,
            'hoje'     => $hoje,
        ];
    }
}
