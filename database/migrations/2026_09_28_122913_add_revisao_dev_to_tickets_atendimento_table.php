<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pedido do Leonardo (24/09): botão "Auditoria" no ticket, pra um
        // humano sinalizar uma conversa pra revisão nossa (desenvolvimento) —
        // NUNCA pra IA analisar. Nomes de campo deliberadamente diferentes de
        // `QaAuditoria`/`AuditoriaContato` (sistemas existentes e não
        // relacionados: QA automático por IA ao encerrar ticket, e moderação
        // de nome de contato) pra não confundir os dois conceitos no código.
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->timestamp('revisao_dev_solicitada_em')->nullable()->after('resumo_ia');
            $table->text('revisao_dev_nota')->nullable()->after('revisao_dev_solicitada_em');
            $table->foreignId('revisao_dev_solicitada_por')->nullable()->after('revisao_dev_nota')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('revisao_dev_concluida_em')->nullable()->after('revisao_dev_solicitada_por');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets_atendimento', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revisao_dev_solicitada_por');
            $table->dropColumn(['revisao_dev_solicitada_em', 'revisao_dev_nota', 'revisao_dev_concluida_em']);
        });
    }
};
