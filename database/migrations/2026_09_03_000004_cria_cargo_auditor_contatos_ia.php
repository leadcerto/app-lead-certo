<?php

use App\Models\Cargo;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        $cargo = Cargo::updateOrCreate(
            ['nome' => 'Auditor & Gestor de Contatos IA'],
            [
                'tipo'                  => 'inteligencia',
                'icone'                 => '📇',
                'descricao'             => 'Auditoria contínua da base, enriquecimento e estruturação de nomes (Primeiro Nome, ID no Meio, Sobrenome), resolução de conflitos e sincronização de marcadores com o Google Contatos.',
                'descricao_cliente'     => 'Higienização e sincronização inteligente de contatos com a agenda corporativa e etiquetas dinâmicas.',
                'detalhes_escopo'       => "1. Formatação e divisão estruturada de nomes (givenName, middleName=ID do banco, familyName=sobrenome/descriptor) em tempo real.\n2. Gerenciamento e transição de etiquetas oficiais no Google Contatos (🚩 NOVOS LEADS ➔ 🚩 LEAD CERTO, 🚩 ⚠️ LEAD INVALIDO, - 00 Sem Nome, - CLIENTE).\n3. Higienização de números telefônicos em formato E.164, detecção de números reciclados e fila de auditoria de divergências.\n4. Sincronização bidirecional delta com a Google People API e controle de linha de base para evitar conflitos falsos.",
                'ferramentas'           => 'Google People API, GoogleEtiquetaService, ContatoSyncService, Auditoria de Conflitos, TelefoneService',
                'kpis'                  => 'Taxa de contatos sincronizados (100%), Conflitos de agenda resolvidos, Acurácia da divisão de nomes',
                'diretriz_ia'           => 'Rigor absoluto na integridade cadastral. Nunca permitir contatos com ID ausente no nome do meio no Google Contatos e manter as etiquetas sempre em conformidade.',
                'cargo_pai_id'          => null,
                'ordem'                 => 15,
                'ativo'                 => true,
                'visivel_para_clientes' => false,
            ]
        );

        $atlasInstrucoes = "# PAPEL E IDENTIDADE\nSeu nome é Atlas. Você é o Auditor e Gestor de Contatos & Google Contatos da Lead Certo.\n\n# MISSÃO\n- Auditar, higienizar e estruturar nomes de todos os contatos no padrão oficial: Primeiro Nome, ID no Nome do Meio e Sobrenome.\n- Sincronizar em tempo real marcadores e grupos oficiais (🚩 NOVOS LEADS ➔ 🚩 LEAD CERTO, 🚩 ⚠️ LEAD INVALIDO, - 00 Sem Nome, - CLIENTE).\n- Resolver conflitos de concorrência, formatar telefones em E.164 canônico e alertar sobre números reciclados.";
        $atlasBase = "- Protocolo de nomes: givenName = Primeiro Nome, middleName = ID do Lead Certo, familyName = Sobrenome.\n- Mapeamento de grupos: 🚩 NOVOS LEADS, 🚩 LEAD CERTO, 🚩 LEADS EM ANÁLISE, 🚩 ⚠️ LEAD INVALIDO, - 00 Sem Nome, - CLIENTE.\n- Proteção: Nunca permitir perda de dados ou conflitos falsos de agenda.";

        $tenantId = \App\Models\Tenant::first()?->id;

        $atlas = User::updateOrCreate(
            ['email' => 'atlas.contatos@leadcerto.com'],
            [
                'tenant_id'        => $tenantId,
                'nome'             => 'Atlas — Auditor de Contatos & Google IA',
                'password'         => Hash::make('LeadCerto@2026'),
                'perfil'           => 'admin',
                'is_ia'            => true,
                'provedor_ia'      => 'openrouter',
                'openrouter_modelo'=> 'anthropic/claude-3.5-sonnet',
                'gemini_email'     => 'atlas.contatos@leadcerto.com',
                'gemini_instrucoes'=> $atlasInstrucoes,
                'base_conhecimento'=> $atlasBase,
                'ativo'            => true,
            ]
        );

        $atlas->cargos()->syncWithoutDetaching([$cargo->id]);
    }

    public function down(): void
    {
        $cargo = Cargo::where('nome', 'Auditor & Gestor de Contatos IA')->first();
        if ($cargo) {
            $cargo->delete();
        }
    }
};
