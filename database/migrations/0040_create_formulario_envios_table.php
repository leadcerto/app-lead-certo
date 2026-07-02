<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulario_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formulario_id')->constrained('formularios');
            $table->foreignId('contato_id')->nullable()->constrained('contatos')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets_atendimento')->nullOnDelete();
            $table->string('dominio_origem', 255)->nullable();   // domínio de onde veio o POST
            $table->json('dados_envio');                          // snapshot completo do que foi enviado
            $table->boolean('confirmado')->default(false);        // double opt-in confirmado
            $table->boolean('processado')->default(false);        // job disparado
            $table->timestamps();

            $table->index(['formulario_id', 'processado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulario_envios');
    }
};
