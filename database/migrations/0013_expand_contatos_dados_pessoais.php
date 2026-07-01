<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contatos', function (Blueprint $table) {

            // ── Nome completo ────────────────────────────────────────────────
            $table->string('sobrenome', 200)->nullable()->after('nome_do_meio');     // Google: familyName
            $table->string('prefixo', 30)->nullable()->after('sobrenome');           // Dr., Sr., Sra., Prof.
            $table->string('sufixo', 30)->nullable()->after('prefixo');              // Jr., Neto, PhD
            $table->string('apelido', 200)->nullable()->after('sufixo');             // Google: nicknames

            // ── Documentos pessoais ─────────────────────────────────────────
            $table->string('cpf', 14)->nullable()->after('apelido');
            $table->string('rg', 20)->nullable()->after('cpf');
            $table->string('passaporte', 30)->nullable()->after('rg');

            // ── Dados pessoais ──────────────────────────────────────────────
            $table->string('genero', 30)->nullable()->after('passaporte');           // Google: genders
            $table->string('estado_civil', 30)->nullable()->after('genero');
            $table->string('nacionalidade', 100)->nullable()->after('estado_civil');
            $table->string('foto_url', 1000)->nullable()->after('nacionalidade');    // Google: photos

            // ── Contatos extra ──────────────────────────────────────────────
            $table->string('telefone_2', 20)->nullable()->after('foto_url');
            $table->string('tipo_telefone', 30)->nullable()->after('telefone_2');    // mobile, home, work
            $table->string('tipo_telefone_2', 30)->nullable()->after('tipo_telefone');
            $table->string('email_2', 200)->nullable()->after('tipo_telefone_2');

            // ── Empresa extra ───────────────────────────────────────────────
            $table->string('departamento', 200)->nullable()->after('empresa');       // Google: organizations.department
            $table->string('tipo_empresa', 50)->nullable()->after('departamento');   // work, other, etc.

            // ── Endereço extra (2º endereço) ────────────────────────────────
            $table->string('endereco_2', 500)->nullable()->after('pais');
            $table->string('cidade_2', 100)->nullable()->after('endereco_2');
            $table->string('estado_2', 50)->nullable()->after('cidade_2');
            $table->string('cep_2', 20)->nullable()->after('estado_2');
            $table->string('pais_2', 50)->nullable()->after('cep_2');

            // ── Redes sociais ───────────────────────────────────────────────
            $table->string('instagram', 200)->nullable()->after('website');
            $table->string('facebook', 200)->nullable()->after('instagram');
            $table->string('linkedin', 200)->nullable()->after('facebook');
            $table->string('twitter', 200)->nullable()->after('linkedin');
            $table->string('tiktok', 200)->nullable()->after('twitter');
            $table->string('youtube', 200)->nullable()->after('tiktok');
            $table->string('whatsapp_negocio', 20)->nullable()->after('youtube');   // WA business oficial
        });
    }

    public function down(): void
    {
        Schema::table('contatos', function (Blueprint $table) {
            $table->dropColumn([
                'sobrenome', 'prefixo', 'sufixo', 'apelido',
                'cpf', 'rg', 'passaporte',
                'genero', 'estado_civil', 'nacionalidade', 'foto_url',
                'telefone_2', 'tipo_telefone', 'tipo_telefone_2', 'email_2',
                'departamento', 'tipo_empresa',
                'endereco_2', 'cidade_2', 'estado_2', 'cep_2', 'pais_2',
                'instagram', 'facebook', 'linkedin', 'twitter', 'tiktok', 'youtube', 'whatsapp_negocio',
            ]);
        });
    }
};
