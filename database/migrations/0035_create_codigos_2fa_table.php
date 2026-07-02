<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codigos_2fa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('codigo', 6);
            $table->string('acao', 100); // ex: "Alteração de senha", "Login novo dispositivo"
            $table->dateTime('expira_em');
            $table->boolean('usado')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'usado', 'expira_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos_2fa');
    }
};
