<?php

namespace App\Jobs;

use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
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
 * Adicionado em 2026-08-28: 4 etiquetas de VALIDAÇÃO de cadastro (eixo
 * independente do funil acima — ver spec
 * docs/superpowers/specs/2026-08-28-validacao-sincronizacao-contatos-design.md).
 * Além de criar os 4 grupos novos, esta task marca TODA a base já
 * vinculada ao tenant (VinculoContatoTenant com google_resource_name) como
 * "leads_em_analise" — ponto de partida antes de qualquer validação rodar
 * (Task 5/6 do plano de implementação processam essa marcação depois).
 *
 * Nomes com o prefixo "Lead Certo - " de propósito — deixa claro que são
 * grupos nossos, sem risco de colidir ou parecer com uma etiqueta que o
 * cliente já tinha criado por conta própria.
 */
class ProvisionarEtiquetasGoogleJob implements ShouldQueue
{
    use Queueable;

    private const SLUGS_FUNIL = ['lead', 'pessoal'];

    private const SLUGS_VALIDACAO = ['novos_leads', 'leads_em_analise', 'lead_certo', 'lead_invalido'];

    public function __construct(private int $googleTokenId) {}

    public function handle(GoogleService $google): void
    {
        $token = GoogleToken::find($this->googleTokenId);
        if (! $token) {
            return;
        }

        foreach ([...self::SLUGS_FUNIL, ...self::SLUGS_VALIDACAO] as $slug) {
            $this->provisionarGrupo($google, $token, $slug);
        }

        $this->marcarBaseExistenteComoEmAnalise($google, $token);
    }

    private function provisionarGrupo(GoogleService $google, GoogleToken $token, string $slug): void
    {
        $etiqueta = Etiqueta::whereNull('tenant_id')->where('slug', $slug)->first();
        if (! $etiqueta) {
            return;
        }

        $jaProvisionado = EtiquetaGoogleGrupo::where('etiqueta_id', $etiqueta->id)
            ->where('tenant_id', $token->tenant_id)
            ->exists();
        if ($jaProvisionado) {
            return;
        }

        $nomeGrupo = 'Lead Certo - ' . ucwords(str_replace('_', ' ', $slug));
        $resourceName = $google->criarGrupoContato($token, $nomeGrupo);
        if (! $resourceName) {
            return;
        }

        EtiquetaGoogleGrupo::create([
            'etiqueta_id'                => $etiqueta->id,
            'tenant_id'                  => $token->tenant_id,
            'google_group_resource_name' => $resourceName,
        ]);
    }

    private function marcarBaseExistenteComoEmAnalise(GoogleService $google, GoogleToken $token): void
    {
        $etiqueta = Etiqueta::whereNull('tenant_id')->where('slug', 'leads_em_analise')->first();
        $grupo    = $etiqueta?->googleGrupoParaTenant($token->tenant_id);

        if (! $grupo) {
            return;
        }

        $resourceNames = VinculoContatoTenant::where('tenant_id', $token->tenant_id)
            ->whereNotNull('google_resource_name')
            ->pluck('google_resource_name')
            ->all();

        if (empty($resourceNames)) {
            return;
        }

        // API do Google aceita no máximo 500 por chamada de members:modify
        foreach (array_chunk($resourceNames, 500) as $lote) {
            $google->modificarMembrosGrupo($token, $grupo->google_group_resource_name, resourceNamesToAdd: $lote);
        }

        $vinculos = VinculoContatoTenant::where('tenant_id', $token->tenant_id)
            ->whereIn('google_resource_name', $resourceNames)
            ->get();

        foreach ($vinculos as $vinculo) {
            $vinculo->etiquetas()->syncWithoutDetaching([$etiqueta->id]);
        }
    }
}
