<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('etiquetas', function (Blueprint $table) {
            $table->id();
            // null = etiqueta do sistema (vale para todos os tenants)
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('nome');
            $table->string('slug', 64);    // 'lead', 'cliente', 'sem_nome', 'inativo'
            $table->string('cor', 16)->default('#6B7280'); // hex para UI
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etiquetas');
    }
};
