<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sugestão 2026-08-20 (Claude, aprovada pelo Leonardo): bloco de "acessos
 * concedidos" na página do agente — visualização rápida do que cada um tem
 * (Gmail, WhatsApp, login...) sem NUNCA guardar senha nenhuma aqui. Guarda
 * só o identificador (e-mail, número, usuário), nunca credencial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acessos_agente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('servico', 100); // ex.: "Gmail", "WhatsApp Messenger", "Login Lead Certo"
            $table->string('identificador', 200); // e-mail/número/usuário — NUNCA senha
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acessos_agente');
    }
};
