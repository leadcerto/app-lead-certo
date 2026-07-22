<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\TicketAtendimento;
use App\Services\SdrResponderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function abrirOuRecuperar(Request $request): JsonResponse
    {
        $request->validate([
            'contato_id' => 'required|integer|exists:contatos,id',
            'tenant_id'  => 'required|integer|exists:tenants,id',
        ]);

        $ticket = TicketAtendimento::withoutGlobalScopes()
            ->where('contato_id', $request->contato_id)
            ->where('tenant_id', $request->tenant_id)
            ->where('status', 'aberto')
            ->first();

        $novo = false;

        if (! $ticket) {
            $ticket = TicketAtendimento::create([
                'tenant_id'          => $request->tenant_id,
                'contato_id'         => $request->contato_id,
                'coluna_kanban'      => \App\Models\KanbanColuna::chaveDeEntrada($request->tenant_id),
                'agente_responsavel' => 'bot',
                'etapa_ia'           => 'etapa_1',
                'status'             => 'aberto',
            ]);
            $novo = true;
        }

        return response()->json([
            'ticket_id'          => $ticket->id,
            'novo'               => $novo,
            'coluna_kanban'      => $ticket->coluna_kanban,
            'agente_responsavel' => $ticket->agente_responsavel,
            'etapa_ia'           => $ticket->etapa_ia,
        ]);
    }

    public function paraFollowup(): JsonResponse
    {
        $tickets = TicketAtendimento::withoutGlobalScopes()
            ->with(['contato'])
            ->where('status', 'aberto')
            ->where('agente_responsavel', 'bot')
            ->where('followup_enviado', false)
            ->whereIn('etapa_ia', ['etapa_1', 'etapa_2'])
            ->whereHas('mensagens', function ($q) {
                $q->where('remetente', 'lead')
                  ->where('enviado_em', '<=', now()->subHours(2));
            })
            ->get();

        return response()->json($tickets);
    }

    public function responderIa(int $ticket, SdrResponderService $sdr): JsonResponse
    {
        $model = TicketAtendimento::withoutGlobalScopes()
            ->with(['contato', 'mensagens', 'persona'])
            ->findOrFail($ticket);

        if ($model->agente_responsavel !== 'bot' || $model->etapa_ia === 'handoff') {
            return response()->json([
                'message' => 'Ticket em atendimento humano ou em handoff — IA não intervém.',
            ], 422);
        }

        $resposta = $sdr->responder($model);

        if (! $resposta) {
            return response()->json(['message' => 'Falha ao gerar resposta da IA.'], 503);
        }

        return response()->json([
            'ticket_id'     => $ticket,
            'sdr_persona_id' => $model->fresh()->sdr_persona_id,
            'resposta'      => $resposta,
        ]);
    }

    public function atualizar(Request $request, int $ticket): JsonResponse
    {
        $model = TicketAtendimento::withoutGlobalScopes()->findOrFail($ticket);

        $allowed = [
            'etapa_ia', 'agente_responsavel', 'coluna_kanban',
            'vendedor_id', 'endereco_saida', 'endereco_destino',
            'lista_itens', 'followup_enviado', 'tag_desfecho',
            'followup_agendado_em', 'status', 'encerrado_em',
        ];

        $model->update($request->only($allowed));

        return response()->json(['ticket_id' => $ticket, 'atualizado' => true]);
    }
}
