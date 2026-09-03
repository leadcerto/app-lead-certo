<?php

use App\Models\Cargo;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $cargo = Cargo::where('nome', 'Auditor & Gestor de Contatos IA')->first();

        if ($cargo) {
            $cargo->update([
                'tipo'                  => 'inteligencia',
                'icone'                 => '📇',
                'descricao'             => 'Auditoria contínua da base, enriquecimento e estruturação de nomes (Primeiro Nome, ID no Meio, Sobrenome), resolução de conflitos e sincronização de marcadores com o Google Contatos.',
                'descricao_cliente'     => 'Higienização e sincronização inteligente de contatos com a agenda corporativa e etiquetas dinâmicas.',
                'detalhes_escopo'       => "1. Formatação e divisão estruturada de nomes (givenName, middleName=ID do banco, familyName=sobrenome/descriptor) em tempo real.\n2. Gerenciamento e transição de etiquetas oficiais no Google Contatos (🚩 NOVOS LEADS ➔ 🚩 LEAD CERTO, 🚩 ⚠️ LEAD INVALIDO, - 00 Sem Nome, - CLIENTE).\n3. Higienização de números telefônicos em formato E.164, detecção de números reciclados e fila de auditoria de divergências.\n4. Sincronização bidirecional delta com a Google People API e controle de linha de base para evitar conflitos falsos.",
                'ferramentas'           => 'Google People API, GoogleEtiquetaService, ContatoSyncService, Auditoria de Conflitos, TelefoneService',
                'kpis'                  => 'Taxa de contatos sincronizados (100%), Conflitos de agenda resolvidos, Acurácia da divisão de nomes',
                'diretriz_ia'           => 'Rigor absoluto na integridade cadastral. Nunca permitir contatos com ID ausente no nome do meio no Google Contatos e manter as etiquetas sempre em conformidade.',
                'ativo'                 => true,
            ]);
        }
    }

    public function down(): void
    {
        // No-op
    }
};
