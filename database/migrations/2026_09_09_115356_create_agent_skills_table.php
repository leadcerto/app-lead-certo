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
        Schema::create('agent_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('cascade');
            $table->enum('origem', ['lead_certo', 'comprada', 'autoral'])->default('autoral');
            $table->string('nome');
            $table->string('titulo');
            $table->string('descricao_curta');
            $table->text('descricao_completa')->nullable();
            $table->longText('instrucoes_base')->nullable();
            $table->boolean('ativa')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'nome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_skills');
    }
};
