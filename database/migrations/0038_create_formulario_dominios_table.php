<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulario_dominios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formulario_id')->constrained('formularios')->cascadeOnDelete();
            $table->string('dominio', 255);    // ex: meusite.com.br (sem protocolo)
            $table->timestamps();

            $table->unique(['formulario_id', 'dominio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formulario_dominios');
    }
};
