<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lista de telefones de clientes reais que o avaliador vai ligar,
     * vinculada a um Perfil GMB (não a um agendamento específico) — o
     * avaliador escolhe da lista quem ainda não foi contatado.
     */
    public function up(): void
    {
        Schema::create('contatos_avaliacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('perfil_id')->constrained('perfis_gmb')->cascadeOnDelete();
            $table->string('nome', 200)->nullable();
            $table->string('telefone', 30);
            $table->timestamp('contatado_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'perfil_id', 'contatado_em'], 'idx_contatos_perfil_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contatos_avaliacao');
    }
};
