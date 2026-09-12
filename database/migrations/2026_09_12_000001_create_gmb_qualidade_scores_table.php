<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_qualidade_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('perfil_gmb_id')->unique()->constrained('perfis_gmb')->cascadeOnDelete();

            $table->unsignedTinyInteger('nota_geral')->nullable();
            $table->json('categorias');
            $table->dateTime('avaliado_em')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_qualidade_scores');
    }
};
