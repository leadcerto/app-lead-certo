<?php

use App\Models\Kanban;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->foreignId('kanban_id')->nullable()->after('tenant_id')
                ->constrained('kanbans')->nullOnDelete();
        });

        // Backfill: hoje todo tenant só tem o Kanban tipo='vendas', então isso é sempre
        // inequívoco — nenhum tenant tem mais de um Kanban ainda. Via query builder (não SQL
        // cru) pra funcionar igual em MySQL (produção) e SQLite (testes).
        Kanban::where('tipo', 'vendas')->get()->each(function (Kanban $kanban) {
            DB::table('tickets_atendimento')
                ->where('tenant_id', $kanban->tenant_id)
                ->whereNull('kanban_id')
                ->update(['kanban_id' => $kanban->id]);
        });
    }

    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_id');
        });
    }
};
