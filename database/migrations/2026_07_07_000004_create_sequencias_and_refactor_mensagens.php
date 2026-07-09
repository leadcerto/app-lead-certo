<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabela pai: sequencias
        Schema::create('sequencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nome');
            $table->string('descricao')->nullable();
            $table->string('coluna_kanban', 50)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'coluna_kanban', 'ativo']);
        });

        // 2. Adiciona FK na tabela de mensagens
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->foreignId('sequencia_id')
                ->nullable()
                ->after('id')
                ->constrained('sequencias')
                ->cascadeOnDelete();
        });

        // 3. Migra mensagens existentes para uma sequência-pai por tenant
        $tenantIds = DB::table('sequencia_mensagens')->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $sequenciaId = DB::table('sequencias')->insertGetId([
                'tenant_id'    => $tenantId,
                'nome'         => 'Boas-vindas',
                'descricao'    => 'Sequência inicial migrada automaticamente.',
                'coluna_kanban' => 'lead_novo',
                'ativo'        => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            DB::table('sequencia_mensagens')
                ->where('tenant_id', $tenantId)
                ->update(['sequencia_id' => $sequenciaId]);
        }
    }

    public function down(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->dropForeign(['sequencia_id']);
            $table->dropColumn('sequencia_id');
        });

        Schema::dropIfExists('sequencias');
    }
};
