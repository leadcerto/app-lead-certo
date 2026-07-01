<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_contato', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contato_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('texto');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('contato_id')->references('id')->on('contatos')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['contato_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_contato');
    }
};
