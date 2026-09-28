<?php

namespace App\Services;

use App\Models\Contato;
use App\Models\Etiqueta;
use App\Models\EtiquetaGoogleGrupo;
use App\Models\GoogleToken;
use App\Models\VinculoContatoTenant;
use Illuminate\Support\Facades\Log;

class GoogleEtiquetaService
{
    /**
     * Mapeamento de slugs do sistema para os nomes oficiais dos marcadores no Google Contatos.
     */
    // Achado real 24/09 (Leonardo, Frete Rio): as etiquetas próprias do
    // sistema (controle gerencial total, incluindo criação de novas no
    // futuro) seguem a convenção de sempre começar com 🚩 — o Leonardo
    // renomeou/criou os grupos reais no Google Contatos direto pela
    // interface com essa convenção, mas o mapeamento ainda só reconhecia os
    // nomes antigos (prefixo "- ") pra sem_nome/fornecedor/pessoal/
    // leads_em_analise/lead_invalido. Resultado: o sistema vinha criando e
    // usando grupos órfãos e vazios em paralelo aos reais (que o Leonardo
    // gerencia manualmente), sem ninguém notar. Nome novo (🚩) sempre
    // primeiro na lista — é o que casa primeiro em sincronizarGrupos() e o
    // que seria usado se precisasse criar do zero pra um tenant novo.
    public const MAPEAMENTO_GRUPOS = [
        'novos_leads'      => ['🚩 NOVOS LEADS', 'Novos Leads', 'Lead Certo - Novos Leads'],
        'lead_certo'       => ['🚩 LEAD CERTO', 'Lead Certo', 'Lead Certo - Lead'],
        'leads_em_analise' => ['🚩 EM ANÁLISE', '🚩 LEADS EM ANÁLISE', 'Leads em Análise', 'Lead Certo - Leads Em Analise'],
        'lead_invalido'    => ['🚩 ⚠️ INVALIDOS', '🚩 ⚠️ INVÁLIDOS', '🚩 ⚠️ LEAD INVALIDO', '🚩 ⚠️ LEAD INVÁLIDO', 'Lead Inválido', 'Lead Certo - Lead Invalido'],
        'sem_nome'         => ['🚩 SEM NOME', '- 00 Sem Nome', 'Sem Nome', 'Lead Certo - Sem Nome'],
        'cliente'          => ['- CLIENTE', 'Cliente', 'Lead Certo - Cliente'],
        'fornecedor'       => ['🚩 FORNECEDORES', '- 00 Fornecedores', 'Fornecedor', 'Lead Certo - Fornecedor'],
        'pessoal'          => ['🚩 PESSOAL', '- 00 Pessoal', 'Pessoal', 'Lead Certo - Pessoal'],
    ];

    public function __construct(private GoogleService $google) {}

    /**
     * Sincroniza e mapeia os grupos de contato do Google com o banco de dados do tenant.
     * Cria os grupos no Google caso ainda não existam.
     */
    public function sincronizarGrupos(GoogleToken $token): array
    {
        $gruposGoogle = $this->google->listarGruposContato($token);
        $mapaGoogle = [];

        foreach ($gruposGoogle as $g) {
            $nome = trim($g['name'] ?? $g['formattedName'] ?? '');
            $resource = $g['resourceName'] ?? '';
            if ($nome && $resource) {
                $mapaGoogle[mb_strtolower($nome)] = $resource;
            }
        }

        $mapeados = [];

        foreach (self::MAPEAMENTO_GRUPOS as $slug => $nomesPossiveis) {
            $etiqueta = Etiqueta::whereNull('tenant_id')->where('slug', $slug)->first();
            if (! $etiqueta) {
                continue;
            }

            $resourceName = null;

            // 1. Tenta casar com algum grupo já existente no Google
            foreach ($nomesPossiveis as $nomeTentativa) {
                $chave = mb_strtolower(trim($nomeTentativa));
                if (isset($mapaGoogle[$chave])) {
                    $resourceName = $mapaGoogle[$chave];
                    break;
                }
            }

            // 2. Se não encontrou, cria com o nome oficial padrão (primeiro da lista)
            if (! $resourceName) {
                $nomeOficial = $nomesPossiveis[0];
                $resourceName = $this->google->criarGrupoContato($token, $nomeOficial);
            }

            if ($resourceName) {
                EtiquetaGoogleGrupo::updateOrCreate(
                    [
                        'etiqueta_id' => $etiqueta->id,
                        'tenant_id'   => $token->tenant_id,
                    ],
                    [
                        'google_group_resource_name' => $resourceName,
                    ]
                );
                $mapeados[$slug] = $resourceName;
            }
        }

        return $mapeados;
    }

    /**
     * Atualiza as etiquetas do contato no Google People API:
     * - Garante que leads com ID da Lead Certo entrem em [🚩 LEAD CERTO] e saiam de [🚩 NOVOS LEADS].
     * - Garante marcadores adicionais (- 00 Sem Nome, - CLIENTE, 🚩 ⚠️ LEAD INVALIDO, etc.).
     */
    public function atualizarMembrosContato(
        GoogleToken $token,
        Contato $contato,
        VinculoContatoTenant $vinculo,
        bool $promoverLeadCerto = false
    ): bool {
        if (! $vinculo->google_resource_name) {
            return false;
        }

        $resourceName = $vinculo->google_resource_name;

        // Garante que os grupos estão mapeados
        $gruposMap = EtiquetaGoogleGrupo::where('tenant_id', $token->tenant_id)
            ->with('etiqueta')
            ->get()
            ->keyBy(fn ($g) => $g->etiqueta?->slug);

        if ($gruposMap->isEmpty()) {
            $this->sincronizarGrupos($token);
            $gruposMap = EtiquetaGoogleGrupo::where('tenant_id', $token->tenant_id)
                ->with('etiqueta')
                ->get()
                ->keyBy(fn ($g) => $g->etiqueta?->slug);
        }

        $grupoNovosLeads = $gruposMap->get('novos_leads')?->google_group_resource_name;
        $grupoLeadCerto  = $gruposMap->get('lead_certo')?->google_group_resource_name;
        $grupoEmAnalise  = $gruposMap->get('leads_em_analise')?->google_group_resource_name;
        $grupoInvalido   = $gruposMap->get('lead_invalido')?->google_group_resource_name;
        $grupoSemNome    = $gruposMap->get('sem_nome')?->google_group_resource_name;
        $grupoCliente    = $gruposMap->get('cliente')?->google_group_resource_name;
        $grupoFornecedor = $gruposMap->get('fornecedor')?->google_group_resource_name;
        $grupoPessoal    = $gruposMap->get('pessoal')?->google_group_resource_name;

        // Se o lead é inválido ou bloqueado
        if ($contato->bloqueado || $contato->opt_out) {
            if ($grupoInvalido) {
                $this->google->modificarMembrosGrupo($token, $grupoInvalido, [$resourceName]);
            }
            if ($grupoNovosLeads) {
                $this->google->modificarMembrosGrupo($token, $grupoNovosLeads, [], [$resourceName]);
            }
            if ($grupoLeadCerto) {
                $this->google->modificarMembrosGrupo($token, $grupoLeadCerto, [], [$resourceName]);
            }
            return true;
        }

        // Transição: sai de NOVOS LEADS / LEADS EM ANÁLISE e entra em LEAD CERTO
        if ($promoverLeadCerto) {
            if ($grupoLeadCerto) {
                $this->google->modificarMembrosGrupo($token, $grupoLeadCerto, [$resourceName]);
            }
            if ($grupoNovosLeads) {
                $this->google->modificarMembrosGrupo($token, $grupoNovosLeads, [], [$resourceName]);
            }
            if ($grupoEmAnalise) {
                $this->google->modificarMembrosGrupo($token, $grupoEmAnalise, [], [$resourceName]);
            }
        }

        // Sem Nome
        if ($grupoSemNome) {
            if ($contato->semNomeReal()) {
                $this->google->modificarMembrosGrupo($token, $grupoSemNome, [$resourceName]);
            } else {
                $this->google->modificarMembrosGrupo($token, $grupoSemNome, [], [$resourceName]);
            }
        }

        // Cliente
        if ($grupoCliente && $contato->tipo_contato === 'cliente') {
            $this->google->modificarMembrosGrupo($token, $grupoCliente, [$resourceName]);
        }

        // Achado real 24/09 (Leonardo): os grupos Fornecedores/Pessoal já
        // eram provisionados (sincronizarGrupos), mas ninguém nunca
        // adicionava um contato a eles de verdade — só o mapeamento com o
        // Google existia, a sincronização por contato não.
        if ($grupoFornecedor && $contato->tipo_contato === 'fornecedor') {
            $this->google->modificarMembrosGrupo($token, $grupoFornecedor, [$resourceName]);
        }
        if ($grupoPessoal && $contato->tipo_contato === 'pessoal') {
            $this->google->modificarMembrosGrupo($token, $grupoPessoal, [$resourceName]);
        }

        return true;
    }
}
