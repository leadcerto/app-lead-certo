<?php

namespace App\Jobs;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\GoogleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PushContatoParaGoogleJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        private int $contatoId,
        private int $tenantId
    ) {}

    public function handle(GoogleService $google): void
    {
        $vinculo = VinculoContatoTenant::where('contato_id', $this->contatoId)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if ($vinculo?->google_resource_name) {
            return;
        }

        $contato = Contato::find($this->contatoId);
        $token   = GoogleToken::where('tenant_id', $this->tenantId)->first();

        if (! $contato || ! $token) {
            return;
        }

        $resourceName = $google->criarContato($token, $contato);

        if (! $resourceName || ! $vinculo) {
            return;
        }

        $vinculo->update(['google_resource_name' => $resourceName]);
        Log::info("Contato #{$this->contatoId} enviado ao Google: {$resourceName}");

        $this->atribuirEtiquetas($google, $token, $vinculo, $contato, $resourceName);
    }

    private function atribuirEtiquetas(
        GoogleService $google,
        GoogleToken $token,
        VinculoContatoTenant $vinculo,
        Contato $contato,
        string $resourceName
    ): void {
        $slugs = $this->determinarSlugs($contato);

        if (empty($slugs)) {
            return;
        }

        $etiquetas = Etiqueta::whereNull('tenant_id')
            ->whereIn('slug', $slugs)
            ->where('ativo', true)
            ->get();

        foreach ($etiquetas as $etiqueta) {
            $grupo = EtiquetaGoogleGrupo::where('etiqueta_id', $etiqueta->id)
                ->where('tenant_id', $this->tenantId)
                ->first();

            if (! $grupo) {
                continue;
            }

            $ok = $google->modificarMembrosGrupo($token, $grupo->google_group_resource_name, [$resourceName]);

            if ($ok) {
                // Evita duplicata na pivot
                $vinculo->etiquetas()->syncWithoutDetaching([$etiqueta->id]);
            }
        }
    }

    private function determinarSlugs(Contato $contato): array
    {
        // Bloqueado tem prioridade absoluta
        if ($contato->bloqueado) {
            return ['bloqueado'];
        }

        if ($contato->opt_out) {
            return ['inativo'];
        }

        $slugs = [];

        // Categoria primária vem do tipo_contato
        $tipo = $contato->tipo_contato ?? 'lead';
        $slugs[] = $tipo; // 'lead' | 'cliente' | 'fornecedor' | 'parceiro' | 'pessoal'

        // Sem nome como etiqueta adicional (independente do tipo)
        if (! $contato->nome || $contato->nome === $contato->telefone) {
            $slugs[] = 'sem_nome';
        }

        return $slugs;
    }
}
