<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SdrResponderJob;
use App\Models\ChamadaPerdida;
use App\Models\Contato;
use App\Models\DispositivoRegistrado;
use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\VinculoContatoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SecretariaEletronicaController extends Controller
{
    // ─── Recebe chamada perdida do app Android ────────────────────────────────

    public function receber(Request $request, string $secretariaToken): JsonResponse
    {
        $tenant = Tenant::where('secretaria_token', $secretariaToken)->first();

        if (! $tenant) {
            return response()->json(['ok' => false, 'erro' => 'Token inválido'], 401);
        }

        $validated = $request->validate([
            'numero_chamador'   => 'required|string|max:20',
            'numero_receptor'   => 'nullable|string|max:20',
            'chamou_em'         => 'nullable|date',
            'duracao_segundos'  => 'integer|min:0',
        ]);

        $numeroChamador  = preg_replace('/\D/', '', $validated['numero_chamador']);
        $numeroReceptor  = preg_replace('/\D/', '', $validated['numero_receptor'] ?? '');
        $duracao         = $validated['duracao_segundos'] ?? 0;
        $chamouEm        = isset($validated['chamou_em']) ? Carbon::parse($validated['chamou_em']) : now();

        // Número BR deve ter 11 dígitos (com DDD) ou 13 (com 55)
        if (strlen($numeroChamador) < 10 || strlen($numeroChamador) > 13) {
            return response()->json(['ok' => true, 'acao' => 'numero_invalido']);
        }

        // Chamada com duração > 5s foi atendida
        if ($duracao > 5) {
            return response()->json(['ok' => true, 'acao' => 'atendida']);
        }

        // Garante formato 55DDDNNNNNNNNN
        if (strlen($numeroChamador) <= 11) {
            $numeroChamador = '55' . $numeroChamador;
        }


        // Registra chamada
        $chamada = ChamadaPerdida::create([
            'tenant_id'        => $tenant->id,
            'numero_chamador'  => $numeroChamador,
            'numero_receptor'  => $numeroReceptor,
            'chamou_em'        => $validated['chamou_em'],
            'duracao_segundos' => $duracao,
            'mensagem_enviada' => false,
        ]);

        // Busca ou cria contato
        $contato = Contato::firstOrCreate(
            ['telefone' => $numeroChamador],
            ['nome' => $numeroChamador, 'origem' => 'ligacao']
        );

        // Vincula ao tenant se ainda não vinculado
        VinculoContatoTenant::firstOrCreate([
            'contato_id' => $contato->id,
            'tenant_id'  => $tenant->id,
        ]);

        // Verifica se já tem ticket bot aberto — não abre duplicado
        $ticketExistente = TicketAtendimento::where('tenant_id', $tenant->id)
            ->where('contato_id', $contato->id)
            ->whereIn('coluna_kanban', ['lead_novo', 'em_atendimento'])
            ->first();

        if ($ticketExistente) {
            $chamada->update([
                'contato_id'       => $contato->id,
                'ticket_id'        => $ticketExistente->id,
                'mensagem_enviada' => false,
            ]);

            Log::info('Secretária: chamada registrada, ticket já existente', [
                'tenant'  => $tenant->id,
                'contato' => $contato->id,
                'ticket'  => $ticketExistente->id,
            ]);

            return response()->json(['ok' => true, 'acao' => 'ticket_existente', 'ticket_id' => $ticketExistente->id]);
        }

        // Cria ticket
        $ticket = TicketAtendimento::create([
            'tenant_id'          => $tenant->id,
            'contato_id'         => $contato->id,
            'coluna_kanban'      => 'lead_novo',
            'agente_responsavel' => 'bot',
            'etapa_ia'           => 'etapa_1',
            'origem'             => 'ligacao',
        ]);

        // Atualiza chamada com IDs vinculados
        $chamada->update([
            'contato_id'          => $contato->id,
            'ticket_id'           => $ticket->id,
            'mensagem_enviada'    => true,
            'mensagem_enviada_em' => now(),
        ]);

        // Dispara o João com contexto de ligação perdida
        SdrResponderJob::dispatch($ticket->id, '', true)->onQueue('default');

        Log::info('Secretária Eletrônica: chamada processada', [
            'tenant'  => $tenant->id,
            'numero'  => $numeroChamador,
            'ticket'  => $ticket->id,
        ]);

        return response()->json(['ok' => true, 'acao' => 'mensagem_enviada', 'ticket_id' => $ticket->id]);
    }

    // ─── Registra device FCM (requer auth) ───────────────────────────────────

    public function registrarDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fcm_token'   => 'required|string|max:255',
            'dispositivo' => 'nullable|string|max:100',
        ]);

        $user = $request->user();

        DispositivoRegistrado::updateOrCreate(
            ['user_id' => $user->id, 'fcm_token' => $validated['fcm_token']],
            [
                'tenant_id'      => $user->tenant_id,
                'dispositivo'    => $validated['dispositivo'] ?? null,
                'ativo'          => true,
                'ultimo_ping_em' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    // ─── Painel: dados da tela ────────────────────────────────────────────────

    public function dadosPainel(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $tenant   = Tenant::find($tenantId);

        $chamadas = ChamadaPerdida::where('tenant_id', $tenantId)
            ->with(['contato', 'ticket'])
            ->orderByDesc('chamou_em')
            ->limit(50)
            ->get()
            ->map(fn ($c) => [
                'id'               => $c->id,
                'numero_chamador'  => $c->numero_chamador,
                'chamou_em'        => $c->chamou_em?->format('d/m/Y H:i'),
                'mensagem_enviada' => $c->mensagem_enviada,
                'ticket_id'        => $c->ticket_id,
                'contato_nome'     => $c->contato?->nome,
            ]);

        $totalMes = ChamadaPerdida::where('tenant_id', $tenantId)
            ->whereMonth('chamou_em', now()->month)
            ->count();

        $dispositivosAtivos = DispositivoRegistrado::whereHas(
            'user', fn ($q) => $q->where('tenant_id', $tenantId)
        )->where('ativo', true)->count();

        return response()->json([
            'secretaria_token'    => $tenant->secretaria_token,
            'mensagem_inicial'    => $tenant->secretaria_mensagem_inicial ?? '',
            'chamadas'            => $chamadas,
            'total_mes'           => $totalMes,
            'dispositivos_ativos' => $dispositivosAtivos,
        ]);
    }

    // ─── Salvar mensagem inicial personalizada ────────────────────────────────

    public function salvarMensagem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mensagem' => 'nullable|string|max:1000',
        ]);

        $tenant = Tenant::find($request->user()->tenant_id);
        $tenant->update(['secretaria_mensagem_inicial' => $validated['mensagem'] ?? null]);

        return response()->json(['ok' => true]);
    }

    // ─── Gerar/rotacionar token ───────────────────────────────────────────────

    public function rotacionarToken(Request $request): JsonResponse
    {
        $tenant = Tenant::find($request->user()->tenant_id);
        $tenant->update(['secretaria_token' => \Illuminate\Support\Str::random(48)]);

        return response()->json(['ok' => true, 'secretaria_token' => $tenant->secretaria_token]);
    }
}
