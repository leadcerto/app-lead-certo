<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chamadas_perdidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('contato_id')->nullable()->constrained('contatos')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets_atendimento')->nullOnDelete();
            $table->string('numero_chamador', 20);
            $table->string('numero_receptor', 20);
            $table->dateTime('chamou_em');
            $table->unsignedInteger('duracao_segundos')->default(0);
            $table->boolean('mensagem_enviada')->default(false);
            $table->dateTime('mensagem_enviada_em')->nullable();
            $table->string('origem_app', 50)->default('android');
            $table->timestamps();

            $table->index(['tenant_id', 'numero_chamador', 'chamou_em'], 'idx_numero_tenant_dia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chamadas_perdidas');
    }
};
