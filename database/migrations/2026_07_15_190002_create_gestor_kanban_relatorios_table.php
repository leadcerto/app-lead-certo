<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestor_kanban_relatorios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->date('semana_inicio');
            $table->date('semana_fim');
            $table->json('dados');
            $table->text('sintese_geral')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'semana_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestor_kanban_relatorios');
    }
};
