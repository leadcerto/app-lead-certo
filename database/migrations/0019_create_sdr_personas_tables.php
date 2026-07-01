<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Limpa tabelas de execução parcial anterior (se existirem)
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('qa_auditorias');
        Schema::dropIfExists('regras_roteamento');
        Schema::dropIfExists('sdr_personas');
        Schema::enableForeignKeyConstraints();

        // ── SDR Personas ─────────────────────────────────────────────────────
        Schema::create('sdr_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nome_interno', 100);          // código interno: "sdr_jovem_sp"
            $table->string('nome_display', 150);          // nome visível: "Lucas - jovem universitário"
            $table->enum('genero', ['masculino', 'feminino', 'neutro'])->default('neutro');
            $table->unsignedTinyInteger('idade_aparente')->nullable();
            $table->string('localidade', 150)->nullable(); // "Rio de Janeiro, RJ"
            $table->enum('tom_de_voz', ['suave', 'formal', 'jovial', 'direto', 'tecnico'])->default('direto');
            $table->text('system_prompt');                 // instruções completas da persona para o LLM
            $table->string('avatar_url', 500)->nullable();
            $table->boolean('ativo')->default(true);
            $table->boolean('is_default')->default(false); // fallback quando nenhuma regra bate
            $table->timestamps();

            $table->index(['tenant_id', 'ativo']);
        });

        // ── Regras de Roteamento (tags de afinidade) ─────────────────────────
        Schema::create('regras_roteamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sdr_persona_id')
                  ->constrained('sdr_personas')
                  ->cascadeOnDelete();
            $table->string('tag', 100); // ex: "atende_b2b", "atende_jovens", "atende_rj"
            $table->unsignedTinyInteger('peso')->default(1); // peso maior = afinidade mais forte
            $table->timestamps();

            $table->index('sdr_persona_id');
            $table->index('tag');
        });

        // ── QA de Auditorias (avaliações de conversa) ─────────────────────────
        Schema::create('qa_auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')
                  ->nullable()
                  ->constrained('tickets_atendimento')
                  ->nullOnDelete();
            $table->foreignId('sdr_persona_id')
                  ->nullable()
                  ->constrained('sdr_personas')
                  ->nullOnDelete();
            $table->decimal('confidence_score', 5, 2)->nullable(); // 0.00 a 100.00
            $table->text('sugestoes_melhoria')->nullable();
            $table->boolean('requer_revisao_humana')->default(false);
            $table->foreignId('revisado_por')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('revisado_em')->nullable();
            $table->string('status', 50)->default('aguardando'); // aguardando | aprovado | rejeitado
            $table->json('payload_avaliacao')->nullable(); // prompt enviado ao LLM juiz + resposta
            $table->timestamp('criado_em')->useCurrent();

            $table->index(['requer_revisao_humana', 'status']);
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_auditorias');
        Schema::dropIfExists('regras_roteamento');
        Schema::dropIfExists('sdr_personas');
    }
};
