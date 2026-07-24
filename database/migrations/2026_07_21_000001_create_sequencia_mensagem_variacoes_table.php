<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequencia_mensagem_variacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sequencia_mensagem_id')->constrained('sequencia_mensagens')->cascadeOnDelete();
            $table->text('conteudo');
            $table->enum('origem', ['humano', 'ia'])->default('humano');
            $table->boolean('protegida')->default(false);
            $table->boolean('ativa')->default(true);
            $table->timestamp('substituida_em')->nullable();
            $table->timestamps();

            $table->index(['sequencia_mensagem_id', 'ativa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequencia_mensagem_variacoes');
    }
};
