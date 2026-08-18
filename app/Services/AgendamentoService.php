<?php

namespace App\Services;

use App\Models\AgendamentoAvaliacao;
use App\Models\PerfilGmb;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AgendamentoService
{
    public function __construct(
        protected SorteioTemplateService $sorteioService,
        protected AtribuicaoAvaliadorService $atribuicaoService,
    ) {}

    /**
     * Agendamento Manual Individual.
     * O admin escolhe perfil, data e opcionalmente template.
     *
     * @param  PerfilGmb  $perfil  Perfil que receberá a avaliação
     * @param  Carbon  $data  Data do agendamento
     * @param  int|null  $templateId  Template fixo (null = sortear automaticamente)
     * @param  int|null  $avaliadorId  Avaliador fixo (null = atribuição automática)
     *
     * @throws \RuntimeException se não houver template ou avaliador disponível
     */
    public function agendarIndividual(
        PerfilGmb $perfil,
        Carbon $data,
        ?int $templateId = null,
        ?int $avaliadorId = null,
    ): AgendamentoAvaliacao {
        // Sortear template se não especificado
        if (! $templateId) {
            $template = $this->sorteioService->sortear($perfil, $data);
            if (! $template) {
                throw new \RuntimeException('Nenhum template disponível para sorteio.');
            }
            $templateId = $template->id;
        }

        // Atribuir avaliador se não especificado
        if (! $avaliadorId) {
            $avaliador = $this->atribuicaoService->resolverAvaliador($perfil, $data);
            if (! $avaliador) {
                throw new \RuntimeException(
                    "Nenhum avaliador disponível para a região {$perfil->city}/{$perfil->state}."
                );
            }
            $avaliadorId = $avaliador->id;
        }

        return AgendamentoAvaliacao::create([
            'tenant_id' => $perfil->tenant_id,
            'perfil_id' => $perfil->id,
            'template_id' => $templateId,
            'avaliador_id' => $avaliadorId,
            'data_agendada' => $data->toDateString(),
            'status' => AgendamentoAvaliacao::STATUS_PENDENTE,
        ]);
    }

    /**
     * Gerador em Lote (Matriz): Dias da Semana × Perfis.
     * O admin informa uma matriz com a quantidade de avaliações por perfil/dia.
     *
     * Formato da matriz:
     * [
     *     perfil_id => [
     *         'segunda' => 2,
     *         'terça'   => 1,
     *         'quarta'  => 0,
     *         ...
     *     ],
     * ]
     *
     * @param  array  $matriz  Matriz perfil × dias com quantidades
     * @param  Carbon  $semanaReferencia  Qualquer dia da semana desejada
     * @param  int  $tenantId  Tenant do admin autenticado — cada perfil_id da
     *                         matriz precisa pertencer a ele (senão o admin de
     *                         um tenant poderia agendar em perfil de outro)
     * @return array ['criados' => int, 'avisos' => string[]]
     */
    public function agendarEmLote(array $matriz, Carbon $semanaReferencia, int $tenantId): array
    {
        $diasMap = [
            'segunda' => Carbon::MONDAY,
            'terca' => Carbon::TUESDAY,
            'terça' => Carbon::TUESDAY,
            'quarta' => Carbon::WEDNESDAY,
            'quinta' => Carbon::THURSDAY,
            'sexta' => Carbon::FRIDAY,
            'sabado' => Carbon::SATURDAY,
            'sábado' => Carbon::SATURDAY,
            'domingo' => Carbon::SUNDAY,
        ];

        $inicioSemana = $semanaReferencia->copy()->startOfWeek(Carbon::MONDAY);

        $criados = 0;
        $avisos = [];
        $templatesSorteados = collect();

        DB::beginTransaction();

        try {
            foreach ($matriz as $perfilId => $dias) {
                $perfil = PerfilGmb::where('tenant_id', $tenantId)->findOrFail($perfilId);

                foreach ($dias as $diaNome => $quantidade) {
                    $diaOffset = $diasMap[mb_strtolower($diaNome)] ?? null;
                    if ($diaOffset === null || $quantidade <= 0) {
                        continue;
                    }

                    $dataAlvo = $inicioSemana->copy()->next($diaOffset);
                    if ($diaOffset === Carbon::MONDAY) {
                        $dataAlvo = $inicioSemana->copy();
                    }

                    for ($i = 0; $i < $quantidade; $i++) {
                        $template = $this->sorteioService->sortear($perfil, $dataAlvo, $templatesSorteados);
                        if (! $template) {
                            $avisos[] = "⚠️ Sem templates disponíveis para \"{$perfil->nome}\".";
                            break;
                        }
                        $templatesSorteados->push($template->id);

                        $avaliador = $this->atribuicaoService->resolverAvaliador($perfil, $dataAlvo);
                        if (! $avaliador) {
                            $avisos[] = "⚠️ Sem avaliador para \"{$perfil->nome}\" ({$perfil->city}/{$perfil->state}).";
                            break;
                        }

                        AgendamentoAvaliacao::create([
                            'tenant_id' => $perfil->tenant_id,
                            'perfil_id' => $perfil->id,
                            'template_id' => $template->id,
                            'avaliador_id' => $avaliador->id,
                            'data_agendada' => $dataAlvo->toDateString(),
                            'status' => AgendamentoAvaliacao::STATUS_PENDENTE,
                        ]);

                        $criados++;
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['criados' => $criados, 'avisos' => $avisos];
    }
}
