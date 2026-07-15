<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_coluna_historico', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ticket_id');
            $table->string('coluna', 50);
            $table->string('coluna_anterior', 50)->nullable();
            $table->timestamp('entrou_em');

            $table->index(['tenant_id', 'coluna', 'entrou_em'], 'kch_tenant_coluna_idx');
            $table->index(['tenant_id', 'coluna_anterior', 'entrou_em'], 'kch_tenant_ant_idx');
            $table->index('ticket_id', 'kch_ticket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_coluna_historico');
    }
};
