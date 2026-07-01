<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            // Categorização primária do contato para a empresa
            // lead       → prospect, ainda não comprou
            // cliente    → já comprou
            // fornecedor → terceirizado (caminhoneiro, baú, ajudante de carga, etc.)
            // parceiro   → presta serviço ao lado (PCR, montagem, mudança)
            // pessoal    → contato pessoal/operacional, não é cliente nem fornecedor
            $table->enum('tipo_contato', ['lead', 'cliente', 'fornecedor', 'parceiro', 'pessoal'])
                ->default('lead')
                ->after('origem');

            // Bloqueado = empresa não quer mais atender ("safado")
            $table->boolean('bloqueado')->default(false)->after('opt_out');
        });
    }

    public function down(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->dropColumn(['tipo_contato', 'bloqueado']);
        });
    }
};
