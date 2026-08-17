<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de perfis/fichas do Google Meu Negócio.
     * Cada registro representa uma empresa que receberá avaliações.
     */
    public function up(): void
    {
        Schema::create('perfis_gmb', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nome', 200);
            $table->string('city', 100);
            $table->char('state', 2);
            $table->text('link_gmb');
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'ativo']);
            $table->index(['city', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfis_gmb');
    }
};
