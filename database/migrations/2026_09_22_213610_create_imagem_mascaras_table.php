<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('imagem_mascaras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nome', 150);
            $table->string('arquivo_url', 500);
            // Bounding box da área transparente detectada automaticamente no
            // upload (varredura do canal alfa) — é ali que a foto de fundo entra.
            $table->unsignedInteger('janela_x');
            $table->unsignedInteger('janela_y');
            $table->unsignedInteger('janela_largura');
            $table->unsignedInteger('janela_altura');
            $table->unsignedInteger('largura_total');
            $table->unsignedInteger('altura_total');
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'ativo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imagem_mascaras');
    }
};
