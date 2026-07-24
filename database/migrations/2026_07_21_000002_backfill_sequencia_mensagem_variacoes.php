<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mensagens = DB::table('sequencia_mensagens')->get(['id', 'tenant_id', 'conteudo']);

        foreach ($mensagens as $msg) {
            if (trim((string) $msg->conteudo) === '') {
                continue; // mensagem só-imagem, sem texto pra virar variação
            }

            $jaTemProtegida = DB::table('sequencia_mensagem_variacoes')
                ->where('sequencia_mensagem_id', $msg->id)
                ->where('protegida', true)
                ->exists();

            if ($jaTemProtegida) {
                continue; // idempotente: não duplica se a migration rodar de novo
            }

            DB::table('sequencia_mensagem_variacoes')->insert([
                'tenant_id'              => $msg->tenant_id,
                'sequencia_mensagem_id'  => $msg->id,
                'conteudo'               => $msg->conteudo,
                'origem'                 => 'humano',
                'protegida'              => true,
                'ativa'                  => true,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sequencia_mensagem_variacoes')
            ->where('origem', 'humano')
            ->where('protegida', true)
            ->delete();
    }
};
