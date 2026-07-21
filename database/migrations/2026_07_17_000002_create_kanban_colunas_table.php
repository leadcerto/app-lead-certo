<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_colunas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('kanban_id')->constrained('kanbans')->cascadeOnDelete();
            $table->string('chave', 50);
            $table->string('label', 60);
            $table->string('emoji', 10)->nullable();
            $table->string('papel', 30);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['kanban_id', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_colunas');
    }
};
