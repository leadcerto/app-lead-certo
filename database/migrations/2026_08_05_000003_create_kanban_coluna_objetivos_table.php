<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_coluna_objetivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('coluna_kanban', 50);
            $table->string('texto', 255);
            $table->unsignedSmallInteger('ordem')->default(1);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'coluna_kanban', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_coluna_objetivos');
    }
};
