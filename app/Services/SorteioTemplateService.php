<?php

namespace App\Services;

use App\Models\AgendamentoAvaliacao;
use App\Models\PerfilGmb;
use App\Models\TemplateAvaliacao;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SorteioTemplateService
{
    /**
     * Sorteia um template para um perfil, obedecendo a cascata de
     * regras anti-repetição descrita no Manual Técnico.
     *
     * @param PerfilGmb  $perfil         Perfil que receberá a avaliação
     * @param Carbon     $data           Data do agendamento
     * @param Collection $jaSorteados    Templates já sorteados neste lote (IDs)
     * @return TemplateAvaliacao|null
     */
    public function sortear(PerfilGmb $perfil, Carbon $data, Collection $jaSorteados = new Collection()): ?TemplateAvaliacao
    {
        $tenantId = $perfil->tenant_id;

        // ── Período de referência ─────────────────────────────────────────────
        $limite60Dias = $data->copy()->subDays(60);
        $inicioSemana = $data->copy()->startOfWeek(Carbon::MONDAY);
        $fimSemana    = $data->copy()->endOfWeek(Carbon::SUNDAY);

        // ── IDs usados no mesmo perfil nos últimos 60 dias ────────────────────
        $usadosRecentemente = AgendamentoAvaliacao::where('perfil_id', $perfil->id)
            ->where('data_agendada', '>=', $limite60Dias->toDateString())
            ->pluck('template_id');

        // ── IDs já sorteados na semana para qualquer perfil ───────────────────
        $usadosNaSemana = AgendamentoAvaliacao::where('tenant_id', $tenantId)
            ->whereBetween('data_agendada', [$inicioSemana->toDateString(), $fimSemana->toDateString()])
            ->pluck('template_id')
            ->merge($jaSorteados)
            ->unique();

        // ── Categorias já agendadas na semana ─────────────────────────────────
        $categoriasNaSemana = TemplateAvaliacao::whereIn('id', $usadosNaSemana)
            ->pluck('categoria_id')
            ->unique();

        // ── Base: templates ativos do tenant ──────────────────────────────────
        $baseQuery = TemplateAvaliacao::where('tenant_id', $tenantId)->ativos();

        // ── Tentativa 1: Todas as 4 regras ────────────────────────────────────
        $candidato = (clone $baseQuery)
            ->whereNotIn('id', $usadosRecentemente)       // Regra 2: sem repetição recente
            ->whereNotIn('id', $usadosNaSemana)           // Regra 3: sem repetição na semana
            ->whereNotIn('categoria_id', $categoriasNaSemana) // Regra 4: diversificação de categoria
            ->inRandomOrder()
            ->first();

        if ($candidato) return $candidato;

        // ── Tentativa 2: Relaxa regra 4 (categoria) ──────────────────────────
        $candidato = (clone $baseQuery)
            ->whereNotIn('id', $usadosRecentemente)
            ->whereNotIn('id', $usadosNaSemana)
            ->inRandomOrder()
            ->first();

        if ($candidato) return $candidato;

        // ── Tentativa 3: Relaxa regra 3 (semana) ─────────────────────────────
        $candidato = (clone $baseQuery)
            ->whereNotIn('id', $usadosRecentemente)
            ->inRandomOrder()
            ->first();

        if ($candidato) return $candidato;

        // ── Último recurso: qualquer template ativo ──────────────────────────
        return (clone $baseQuery)
            ->inRandomOrder()
            ->first();
    }

    /**
     * Verifica se um perfil pode receber mais avaliações na semana.
     * Trava rígida: máximo 2 avaliações por semana por perfil.
     *
     * @return bool true se pode agendar, false se atingiu o limite
     */
    public function podeAgendar(PerfilGmb $perfil, Carbon $data): bool
    {
        $inicioSemana = $data->copy()->startOfWeek(Carbon::MONDAY);
        $fimSemana    = $data->copy()->endOfWeek(Carbon::SUNDAY);

        $count = AgendamentoAvaliacao::where('perfil_id', $perfil->id)
            ->whereBetween('data_agendada', [$inicioSemana->toDateString(), $fimSemana->toDateString()])
            ->count();

        return $count < 2;
    }

    /**
     * Conta quantas avaliações restam na semana para o perfil.
     */
    public function vagasNaSemana(PerfilGmb $perfil, Carbon $data): int
    {
        $inicioSemana = $data->copy()->startOfWeek(Carbon::MONDAY);
        $fimSemana    = $data->copy()->endOfWeek(Carbon::SUNDAY);

        $count = AgendamentoAvaliacao::where('perfil_id', $perfil->id)
            ->whereBetween('data_agendada', [$inicioSemana->toDateString(), $fimSemana->toDateString()])
            ->count();

        return max(0, 2 - $count);
    }
}
