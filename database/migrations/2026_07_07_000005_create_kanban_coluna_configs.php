<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_coluna_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('coluna_kanban', 50);
            $table->longText('ia_contexto')->nullable();
            $table->boolean('ia_ativo')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'coluna_kanban']);
        });

        // Migra o ia_contexto global para a coluna em_atendimento
        $tenants = DB::table('tenants')
            ->whereNotNull('ia_contexto')
            ->where('ia_contexto', '!=', '')
            ->get(['id', 'ia_contexto']);

        foreach ($tenants as $tenant) {
            DB::table('kanban_coluna_configs')->insertOrIgnore([
                'tenant_id'     => $tenant->id,
                'coluna_kanban' => 'em_atendimento',
                'ia_contexto'   => $tenant->ia_contexto,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_coluna_configs');
    }
};
