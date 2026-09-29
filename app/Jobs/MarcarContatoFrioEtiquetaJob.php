<?php

namespace App\Jobs;

use App\Models\Etiqueta;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Marca um contato extraído de grupo/comunidade do WhatsApp (origem
 * whatsapp_grupo) com a etiqueta 🚩 FRIOS no Google — espelha
 * MarcarNovoLeadEtiquetaJob de propósito (mesmo padrão: espera o vínculo
 * já ter google_resource_name, resolve o grupo provisionado do tenant,
 * adiciona no Google e marca o pivot local). Ver plano em
 * [[whatsapp-extracao-contatos-grupos]] — dispatch com delay a partir de
 * ImportarParticipantesGrupos, dando tempo de PushContatoParaGoogleJob
 * criar o contato no Google primeiro.
 */
class MarcarContatoFrioEtiquetaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private int $vinculoId) {}

    public function handle(GoogleService $google): void
    {
        $vinculo = VinculoContatoTenant::with('contato')->find($this->vinculoId);
        if (! $vinculo || ! $vinculo->google_resource_name) {
            return;
        }

        if ($vinculo->contato?->origem !== 'whatsapp_grupo') {
            return;
        }

        $frios = Etiqueta::whereNull('tenant_id')->where('slug', 'frios')->first();
        $grupo = $frios?->googleGrupoParaTenant($vinculo->tenant_id);
        if (! $frios || ! $grupo) {
            return;
        }

        $token = GoogleToken::where('tenant_id', $vinculo->tenant_id)->first();
        if (! $token) {
            return;
        }

        $ok = $google->modificarMembrosGrupo($token, $grupo->google_group_resource_name, resourceNamesToAdd: [$vinculo->google_resource_name]);

        if ($ok) {
            $vinculo->etiquetas()->syncWithoutDetaching([$frios->id]);
        }
    }
}
