<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulario_campos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formulario_id')->constrained('formularios')->cascadeOnDelete();
            $table->string('chave', 50);        // identificador interno (slug, ex: "faturamento")
            $table->string('rotulo', 100);      // rótulo exibido no formulário (ex: "Faturamento mensal")
            $table->enum('tipo', ['texto', 'email', 'telefone', 'numero', 'selecao', 'area_texto'])
                  ->default('texto');
            $table->json('opcoes')->nullable();  // para tipo=selecao: ["Opção A","Opção B"]
            $table->boolean('obrigatorio')->default(false);
            $table->unsignedTinyInteger('ordem')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulario_campos');
    }
};
