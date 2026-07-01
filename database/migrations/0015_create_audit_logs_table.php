<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id')->nullable();   // null = sistema / IA
            $table->string('usuario_nome', 200)->nullable();        // snapshot do nome no momento
            $table->string('tabela', 100);                         // contatos, vinculos_contato_tenant…
            $table->unsignedBigInteger('registro_id');             // ID do registro alterado
            $table->string('acao', 50);                            // criar, atualizar, inativar, aprovar_nome…
            $table->string('campo', 100)->nullable();              // campo alterado (null = ação em lote)
            $table->text('valor_antigo')->nullable();
            $table->text('valor_novo')->nullable();
            $table->json('contexto')->nullable();                  // tenant_id, IP, etc.
            $table->timestamp('criado_em')->useCurrent();

            $table->index(['tabela', 'registro_id']);
            $table->index('usuario_id');
            $table->index('criado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
