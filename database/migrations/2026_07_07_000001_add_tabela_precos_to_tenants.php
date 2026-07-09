<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('tabela_precos_pdf_path')->nullable()->after('ia_contexto');
            $table->text('tabela_precos_texto')->nullable()->after('tabela_precos_pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['tabela_precos_pdf_path', 'tabela_precos_texto']);
        });
    }
};
