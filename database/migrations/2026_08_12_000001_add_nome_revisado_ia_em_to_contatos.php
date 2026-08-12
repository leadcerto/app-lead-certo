<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            // Marca a última vez que `contatos:limpar-nomes` avaliou este contato
            // via IA. Antes o comando filtrava por regex de "nome suspeito" antes
            // de mandar pra IA — isso deixava passar batido nomes que não são de
            // pessoa mas também não têm formatação suspeita (ex: "Pai Saudade 💕",
            // "Block rastreadores"). Agora todo contato passa pela IA pelo menos
            // uma vez; este campo evita reprocessar pra sempre todo santo dia.
            $table->timestamp('nome_revisado_ia_em')->nullable()->after('sobrenome');
        });
    }

    public function down(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->dropColumn('nome_revisado_ia_em');
        });
    }
};
