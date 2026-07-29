<?php

use App\Models\Tenant;
use App\Models\TicketAtendimento;
use App\Models\WhatsappCanal;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // A migration 2026_07_27_000004 criou os WhatsappCanal e os vinculou aos
        // Kanbans, mas nunca tocou nos tickets já abertos — eles ficam com
        // whatsapp_canal_id NULL. Como o webhook só preenche esse campo na
        // CRIAÇÃO do ticket (ou na reativação de um ticket encerrado), um
        // ticket já aberto que recebe uma nova mensagem nunca se autocorrige.
        // Sem este backfill, todo ticket em andamento no momento do deploy
        // perde silenciosamente bot, sequências, follow-ups, menus de botão
        // e resposta do agente humano (token nulo -> falha ao resolver canal).
        Tenant::all()->each(function (Tenant $tenant) {
            $canal = WhatsappCanal::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('provider', 'uazapi')
                ->first();

            if (! $canal) {
                return; // tenant sem canal uazapi migrado — nada a preencher
            }

            TicketAtendimento::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereNull('whatsapp_canal_id')
                ->update(['whatsapp_canal_id' => $canal->id]);
        });
    }

    public function down(): void
    {
        // Backfill não é destrutivo o suficiente para reverter com segurança
        // (não há como distinguir, depois do fato, um whatsapp_canal_id que
        // foi preenchido por este backfill de um que já veio assim de outra
        // origem). Reversão manual, se necessário.
    }
};
