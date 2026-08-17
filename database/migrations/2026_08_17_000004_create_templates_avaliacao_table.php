<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de templates de avaliação.
     * Textos sugeridos que os avaliadores oferecem por telefone ao cliente real
     * (o cliente decide se usa, adapta ou ignora ao publicar na própria conta).
     * O código é um identificador curto único por tenant.
     */
    public function up(): void
    {
        Schema::create('templates_avaliacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('codigo', 30);
            $table->text('texto');
            $table->foreignId('categoria_id')
                  ->constrained('categorias_template')
                  ->cascadeOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'codigo']);
            $table->index(['tenant_id', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates_avaliacao');
    }
};
