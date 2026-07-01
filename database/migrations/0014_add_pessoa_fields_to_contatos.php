<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            // Tipo de pessoa e status de validação
            $table->enum('tipo_pessoa', ['pf', 'pj'])->nullable()->after('origem');
            $table->enum('status_validacao', ['pendente', 'aprovado', 'inconsistente'])->default('pendente')->after('tipo_pessoa');

            // Campos Pessoa Jurídica
            $table->string('cnpj', 18)->nullable()->after('cpf');
            $table->string('razao_social', 300)->nullable()->after('cnpj');
            $table->string('nome_fantasia', 300)->nullable()->after('razao_social');
            $table->string('inscricao_estadual', 30)->nullable()->after('nome_fantasia');
            $table->string('inscricao_municipal', 30)->nullable()->after('inscricao_estadual');

            // Soft Delete — jamais excluir fisicamente
            $table->softDeletes('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_pessoa', 'status_validacao',
                'cnpj', 'razao_social', 'nome_fantasia', 'inscricao_estadual', 'inscricao_municipal',
                'deleted_at',
            ]);
        });
    }
};
