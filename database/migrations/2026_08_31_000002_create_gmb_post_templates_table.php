<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gmb_post_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('categoria', 100)->default('geral'); // dicas, promocoes, institucional, novidades
            $table->string('titulo_template');
            $table->text('texto_template');
            $table->string('cta_tipo_padrao', 50)->default('LEARN_MORE');
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmb_post_templates');
    }
};
