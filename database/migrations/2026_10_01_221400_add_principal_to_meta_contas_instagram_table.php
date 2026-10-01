<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vistoria pedida pelo Leonardo (01/10): hipótese de que a ferramenta só
 * suporta 1 conta de Instagram por tenant era parcialmente errada — o
 * modelo já suporta N contas (`meta_contas_instagram` sem unique em
 * tenant_id). O que faltava era marcar qual é a "principal", pra:
 * (a) o fallback em MetaPostController::store() parar de pegar uma conta
 * arbitrária (bug real encontrado na vistoria) quando o formulário não
 * envia qual conta usar; (b) dar ao usuário uma forma explícita de
 * escolher a conta padrão na tela de Integrações.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_contas_instagram', function (Blueprint $table) {
            $table->boolean('principal')->default(false)->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('meta_contas_instagram', function (Blueprint $table) {
            $table->dropColumn('principal');
        });
    }
};
