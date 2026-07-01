<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contatos_pendentes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('telefone', 20)->nullable();
            $table->string('nome', 200)->nullable();

            // Dados completos vindos do Google (para criar o contato ao resolver)
            $table->json('dados_brutos');

            // Tipo de conflito que gerou este pendente
            $table->string('tipo_conflito', 60); // 'numero_possivelmente_reciclado', 'sem_telefone'

            // Referência ao contato já existente com o mesmo telefone
            $table->unsignedBigInteger('contato_existente_id')->nullable();
            $table->string('nome_existente', 200)->nullable();
            $table->decimal('similaridade_nome', 5, 2)->nullable(); // ex: 23.40

            // Resolução pelo Auditor
            $table->enum('status', ['aguardando', 'fundido', 'novo_criado', 'descartado'])->default('aguardando');
            $table->unsignedBigInteger('resolvido_por')->nullable();
            $table->timestamp('resolvido_em')->nullable();
            $table->text('observacoes')->nullable();

            $table->timestamp('criado_em')->useCurrent();

            $table->index(['tenant_id', 'status']);
            $table->index('telefone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contatos_pendentes');
    }
};
