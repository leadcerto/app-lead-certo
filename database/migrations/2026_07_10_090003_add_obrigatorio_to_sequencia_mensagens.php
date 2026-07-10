<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            // Quando true, a mensagem é enviada mesmo se o lead já tiver saído
            // da coluna da sequência (ex: respondeu "oi" e o ticket avançou pra
            // em_atendimento) — ignora o auto-cancelamento normal do job.
            $table->boolean('obrigatorio')->default(false)->after('button_settings');
        });
    }

    public function down(): void
    {
        Schema::table('sequencia_mensagens', function (Blueprint $table) {
            $table->dropColumn('obrigatorio');
        });
    }
};
