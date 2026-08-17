<?php

namespace App\Services;

use App\Models\AgendamentoAvaliacao;
use App\Models\PerfilGmb;
use App\Models\User;
use Carbon\Carbon;

class AtribuicaoAvaliadorService
{
    /**
     * Resolve o avaliador mais adequado para um perfil em uma data.
     *
     * Algoritmo de balanceamento de carga:
     * 1. Filtra users do mesmo tenant do perfil, com perfil = 'avaliador' e ativo = true
     * 2. Match geográfico: city + state do avaliador = city + state do perfil
     * 3. Conta agendamentos de cada avaliador na semana corrente
     * 4. Retorna o avaliador com menor carga (menos agendamentos)
     *
     * @param PerfilGmb $perfil  O perfil que receberá a avaliação
     * @param Carbon    $data    A data do agendamento (para calcular a semana)
     * @return User|null         O avaliador selecionado, ou null se nenhum disponível
     */
    public function resolverAvaliador(PerfilGmb $perfil, Carbon $data): ?User
    {
        $inicioSemana = $data->copy()->startOfWeek(Carbon::MONDAY);
        $fimSemana    = $data->copy()->endOfWeek(Carbon::SUNDAY);

        return User::where('tenant_id', $perfil->tenant_id)
            ->where('perfil', 'avaliador')
            ->where('ativo', true)
            ->where('city', $perfil->city)
            ->where('state', $perfil->state)
            ->withCount(['agendamentosAvaliacao as carga_semana' => function ($query) use ($inicioSemana, $fimSemana) {
                $query->whereBetween('data_agendada', [
                    $inicioSemana->toDateString(),
                    $fimSemana->toDateString(),
                ]);
            }])
            ->orderBy('carga_semana', 'asc')
            ->first();
    }

    /**
     * Lista todos os avaliadores disponíveis para um perfil,
     * ordenados do menos carregado ao mais carregado.
     */
    public function listarDisponiveisOrdenados(PerfilGmb $perfil, Carbon $data): \Illuminate\Database\Eloquent\Collection
    {
        $inicioSemana = $data->copy()->startOfWeek(Carbon::MONDAY);
        $fimSemana    = $data->copy()->endOfWeek(Carbon::SUNDAY);

        return User::where('tenant_id', $perfil->tenant_id)
            ->where('perfil', 'avaliador')
            ->where('ativo', true)
            ->where('city', $perfil->city)
            ->where('state', $perfil->state)
            ->withCount(['agendamentosAvaliacao as carga_semana' => function ($query) use ($inicioSemana, $fimSemana) {
                $query->whereBetween('data_agendada', [
                    $inicioSemana->toDateString(),
                    $fimSemana->toDateString(),
                ]);
            }])
            ->orderBy('carga_semana', 'asc')
            ->get();
    }
}
