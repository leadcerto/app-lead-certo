<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            if (!Schema::hasColumn('cargos', 'detalhes_escopo')) {
                $table->text('detalhes_escopo')->nullable()->after('descricao');
            }
            if (!Schema::hasColumn('cargos', 'ferramentas')) {
                $table->string('ferramentas', 500)->nullable()->after('detalhes_escopo');
            }
            if (!Schema::hasColumn('cargos', 'kpis')) {
                $table->string('kpis', 500)->nullable()->after('ferramentas');
            }
            if (!Schema::hasColumn('cargos', 'diretriz_ia')) {
                $table->text('diretriz_ia')->nullable()->after('kpis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            if (Schema::hasColumn('cargos', 'diretriz_ia')) {
                $table->dropColumn('diretriz_ia');
            }
            if (Schema::hasColumn('cargos', 'kpis')) {
                $table->dropColumn('kpis');
            }
            if (Schema::hasColumn('cargos', 'ferramentas')) {
                $table->dropColumn('ferramentas');
            }
            if (Schema::hasColumn('cargos', 'detalhes_escopo')) {
                $table->dropColumn('detalhes_escopo');
            }
        });
    }
};
