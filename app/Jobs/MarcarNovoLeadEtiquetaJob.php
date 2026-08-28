<?php

namespace App\Jobs;

use App\Models\Etiqueta;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Marca um VinculoContatoTenant recém-criado como "novos_leads" (spec
 * seção 5) — só se o tenant já tiver o grupo leads_em_analise provisionado
 * (Task 3 do plano), senão pula: evita marcar um contato do próprio lote
 * inicial de conexão como se fosse um lead novo chegando depois. Quem
 * ainda não foi marcado por nenhuma das duas etiquetas fica pra a próxima
 * varredura do comando em lote (Task 5) pegar.
 */
class MarcarNovoLeadEtiquetaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private int $vinculoId) {}

    public function handle(GoogleService $google): void
    {
        $vinculo = VinculoContatoTenant::find($this->vinculoId);
        if (! $vinculo || ! $vinculo->google_resource_name) {
            return;
        }

        $emAnalise = Etiqueta::whereNull('tenant_id')->where('slug', 'leads_em_analise')->first();
        if (! $emAnalise || ! $emAnalise->googleGrupoParaTenant($vinculo->tenant_id)) {
            return; // tenant ainda nao provisionou as etiquetas de validacao
        }

        $novosLeads = Etiqueta::whereNull('tenant_id')->where('slug', 'novos_leads')->first();
        $grupo      = $novosLeads?->googleGrupoParaTenant($vinculo->tenant_id);
        if (! $novosLeads || ! $grupo) {
            return;
        }

        // VinculoContatoTenant não tem relação tenant() — consulta direto
        // pelo tenant_id em vez de assumir uma relação que não existe.
        $token = GoogleToken::where('tenant_id', $vinculo->tenant_id)->first();
        if (! $token) {
            return;
        }

        $ok = $google->modificarMembrosGrupo($token, $grupo->google_group_resource_name, resourceNamesToAdd: [$vinculo->google_resource_name]);

        if ($ok) {
            $vinculo->etiquetas()->syncWithoutDetaching([$novosLeads->id]);
        }
    }
}
