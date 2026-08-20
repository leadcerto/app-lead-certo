<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redesenho pedido pelo Leonardo em 2026-08-20: o cliente não fala com uma
 * PESSOA (ex.: "Adriana"), fala com um SETOR (ex.: "Suporte") — o cargo por
 * trás resolve quem responde. E o processo interno fica mais robusto: não é
 * só "anotar e levar pra reunião", vira um caso com análise de viabilidade
 * (faz sentido implementar? tempo estimado? quantas empresas se
 * beneficiariam — filosofia: atender a necessidade da MAIORIA, não pedido
 * pontual de uma empresa só).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            // Só cargos marcados aparecem como "setor" pro cliente escolher
            // — evita expor cargo interno/dormente (ex.: Gestor de SEO) que
            // não faz sentido nenhum cliente escrever direto.
            $table->boolean('visivel_para_clientes')->default(false)->after('ativo');
        });

        Schema::table('feedbacks_agente', function (Blueprint $table) {
            $table->foreignId('cargo_id')->nullable()->after('user_id')->constrained('cargos')->nullOnDelete();
            $table->enum('status', ['pendente', 'em_analise', 'concluido'])->default('pendente')->after('resposta');
            $table->text('relatorio_analise')->nullable()->after('status');
            $table->boolean('implementacao_faz_sentido')->nullable()->after('relatorio_analise');
            $table->string('tempo_estimado_execucao', 100)->nullable()->after('implementacao_faz_sentido');
            $table->unsignedInteger('empresas_beneficiadas_estimado')->nullable()->after('tempo_estimado_execucao');
        });
    }

    public function down(): void
    {
        Schema::table('feedbacks_agente', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cargo_id');
            $table->dropColumn(['status', 'relatorio_analise', 'implementacao_faz_sentido', 'tempo_estimado_execucao', 'empresas_beneficiadas_estimado']);
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->dropColumn('visivel_para_clientes');
        });
    }
};
