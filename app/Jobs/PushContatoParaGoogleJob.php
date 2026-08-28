<?php

namespace App\Jobs;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use App\Services\ContatoSyncService;
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
        private int $tenantId,
        private ?string $pushName = null
    ) {}

    public function handle(GoogleService $google, ContatoSyncService $sync): void
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

        $resourceName = $google->criarContato($token, $contato, $this->pushName);

        if (! $resourceName || ! $vinculo) {
            return;
        }

        $vinculo->update(['google_resource_name' => $resourceName]);
        Log::info("Contato #{$this->contatoId} enviado ao Google: {$resourceName}");

        $vinculo->update(['google_valores_enviados' => $this->linhaBaseEnviada($google, $sync, $contato)]);

        $this->atribuirEtiquetas($google, $token, $vinculo, $contato, $resourceName);
    }

    /**
     * A linha de base tem que ser o que foi REALMENTE enviado ao Google, não o
     * valor cru do banco: GoogleService::criarContato() manda
     * givenName = limparNome($contato->nome) (ou 'Sem Nome') e
     * familyName = limparNome($descriptor). Gravar o valor cru fazia o pull
     * seguinte comparar o que voltou do Google contra algo que nunca foi
     * enviado — conflito falso em todo contato com nome fora do title case ou
     * com sobrenome. As chaves aqui espelham exatamente o que o pull lê:
     * 'nome' ← givenName, 'sobrenome' ← familyName.
     *
     * O 'nome' leva os DOIS sanitizadores em cadeia porque os dois lados do
     * ciclo usam funções diferentes: o push manda
     * GoogleService::limparNome($nome) como givenName, e o pull aplica
     * ContatoSyncService::limparNome() EM CIMA do givenName que volta — e esse
     * segundo ainda remove índice de agenda de 3-6 dígitos e palavra duplicada
     * consecutiva. Gravar só o valor enviado deixava a linha de base divergindo
     * do que o ciclo completo produz na volta pra "Kamily Kamily" (pushName do
     * WhatsApp) ou "Padaria 2000" (índice de agenda) → pendência falsa de
     * auditoria ou ContatoPendente falso de "número reciclado". A linha de base
     * tem que guardar o resultado do ciclo INTEIRO, não só o que foi enviado.
     *
     * O 'sobrenome' NÃO leva a segunda passada de propósito: o pull lê
     * familyName com trim puro (ContatoSyncService::extrairDados), sem
     * limparNome() — a assimetria só existe no nome.
     */
    private function linhaBaseEnviada(GoogleService $google, ContatoSyncService $sync, Contato $contato): array
    {
        $linhaBase = [
            'nome' => $contato->semNomeReal()
                ? 'Sem Nome'
                : $sync->limparNome($google->limparNome((string) $contato->nome)),
        ];

        // Mesma resolução de descriptor de GoogleService::criarContato(): sem
        // sobrenome local, o familyName enviado vem do pushName do WhatsApp.
        $descriptor = $contato->sobrenome
            ?: ($this->pushName ? $google->extrairDescriptor($this->pushName) : null);

        if ($descriptor) {
            $linhaBase['sobrenome'] = $google->limparNome($descriptor);
        }

        // empresa/email vão verbatim pro Google — nada a transformar.
        foreach (['empresa', 'email'] as $campo) {
            if (! empty($contato->$campo)) {
                $linhaBase[$campo] = (string) $contato->$campo;
            }
        }

        return $linhaBase;
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
        $slugs[] = $tipo; // 'lead' | 'cliente' | 'fornecedor' | 'parceiro' | 'colaborador' | 'pessoal'

        // Sem nome como etiqueta adicional (independente do tipo)
        if (! $contato->nome || $contato->nome === $contato->telefone) {
            $slugs[] = 'sem_nome';
        }

        return $slugs;
    }
}
