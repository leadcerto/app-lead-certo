<?php
// database/migrations/2026_08_07_000002_add_resposta_to_alertas_internos.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alertas_internos', function (Blueprint $table) {
            // Só usados por alertas tipo 'duvida_ia' (Regra 2) — os demais tipos
            // (já existentes desde o Bloco 1/2) nunca preenchem esses campos.
            $table->text('resposta')->nullable()->after('conteudo');
            $table->timestamp('respondido_em')->nullable()->after('resposta');
        });
    }

    public function down(): void
    {
        Schema::table('alertas_internos', function (Blueprint $table) {
            $table->dropColumn(['resposta', 'respondido_em']);
        });
    }
};
