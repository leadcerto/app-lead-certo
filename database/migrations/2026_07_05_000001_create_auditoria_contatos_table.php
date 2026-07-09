<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_contatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contato_id')->constrained('contatos')->onDelete('cascade');
            $table->string('tipo', 50);          // telefone_invalido | nome_suspeito
            $table->string('campo', 30);         // telefone | nome
            $table->text('valor_original');      // valor problemático atual no banco
            $table->text('valor_sugerido')->nullable(); // sugestão automática (se houver)
            $table->text('observacao')->nullable();     // explicação do problema
            $table->string('status', 30)->default('pendente'); // pendente | resolvido | ignorado
            $table->timestamp('resolvido_em')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'tipo']);
            $table->index('contato_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_contatos');
    }
};
