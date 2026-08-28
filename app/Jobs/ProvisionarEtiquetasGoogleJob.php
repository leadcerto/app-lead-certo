<?php

namespace App\Jobs;

use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pedido do Leonardo (2026-08-28): a mesma orientação de etiquetas vale pra
 * qualquer empresa, não só uma — disparado sem delay quando um GoogleToken
 * novo é criado (ver GoogleToken::booted()), cria os grupos "Lead Certo -
 * Lead" e "Lead Certo - Pessoal" na agenda do Google do tenant que acabou
 * de conectar, e liga cada um à Etiqueta global correspondente
 * (etiqueta_google_grupos). Daqui em diante:
 *   - "lead" é atribuído automaticamente pelo Lead Certo a todo contato novo
 *     (PushContatoParaGoogleJob::atribuirEtiquetas(), já existia — só
 *     faltava o grupo existir pra ele encontrar).
 *   - "pessoal" é o time do cliente quem marca manualmente no Google dele;
 *     ContatoSyncService::detectarTipoContato() já lê isso no pull e grava
 *     em Contato::tipo_contato — Contato::excluidoDoFunilComercial() usa
 *     esse campo pra impedir a criação de ticket novo de vendas.
 *
 * Nomes com o prefixo "Lead Certo - " de propósito — deixa claro que são
 * grupos nossos, sem risco de colidir ou parecer com uma etiqueta que o
 * cliente já tinha criado por conta própria.
 */
class ProvisionarEtiquetasGoogleJob implements ShouldQueue
{
    use Queueable;

    private const SLUGS = ['lead', 'pessoal'];

    public function __construct(private int $googleTokenId) {}

    public function handle(GoogleService $google): void
    {
        $token = GoogleToken::find($this->googleTokenId);
        if (! $token) {
            return;
        }

        foreach (self::SLUGS as $slug) {
            $etiqueta = Etiqueta::whereNull('tenant_id')->where('slug', $slug)->first();
            if (! $etiqueta) {
                continue;
            }

            $jaProvisionado = EtiquetaGoogleGrupo::where('etiqueta_id', $etiqueta->id)
                ->where('tenant_id', $token->tenant_id)
                ->exists();
            if ($jaProvisionado) {
                continue;
            }

            $nomeGrupo = 'Lead Certo - ' . ucfirst($slug);
            $resourceName = $google->criarGrupoContato($token, $nomeGrupo);
            if (! $resourceName) {
                continue;
            }

            EtiquetaGoogleGrupo::create([
                'etiqueta_id'                => $etiqueta->id,
                'tenant_id'                  => $token->tenant_id,
                'google_group_resource_name' => $resourceName,
            ]);
        }
    }
}
