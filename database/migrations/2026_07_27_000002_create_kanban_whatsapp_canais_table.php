<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_whatsapp_canais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kanban_id')->constrained('kanbans')->cascadeOnDelete();
            $table->foreignId('whatsapp_canal_id')->constrained('whatsapp_canais')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['kanban_id', 'whatsapp_canal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_whatsapp_canais');
    }
};
