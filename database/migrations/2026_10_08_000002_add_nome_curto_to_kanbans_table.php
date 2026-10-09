<?php

use App\Models\Kanban;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->string('nome_curto', 20)->nullable()->after('nome');
        });

        Kanban::where('tipo', 'vendas')->whereNull('nome_curto')->update(['nome_curto' => 'Atendimentos']);
    }

    public function down(): void
    {
        Schema::table('kanbans', function (Blueprint $table) {
            $table->dropColumn('nome_curto');
        });
    }
};
