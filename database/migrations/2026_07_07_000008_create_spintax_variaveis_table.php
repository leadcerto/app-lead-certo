<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spintax_variaveis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('nome', 50);
            $table->string('label', 100);
            $table->text('opcoes');
            $table->timestamps();
            $table->unique(['tenant_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spintax_variaveis');
    }
};
