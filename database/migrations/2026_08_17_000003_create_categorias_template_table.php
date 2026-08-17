<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de categorias dos templates de avaliação.
     * O nome pode conter emojis (ex: "⭐ Atendimento", "🚚 Entrega").
     */
    public function up(): void
    {
        Schema::create('categorias_template', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nome', 100);
            $table->timestamps();

            $table->unique(['tenant_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_template');
    }
};
