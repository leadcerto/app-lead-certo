<?php
// database/migrations/2026_08_06_000001_create_alertas_internos_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas_internos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets_atendimento')->nullOnDelete();
            $table->string('tipo', 50);
            $table->string('titulo', 150);
            $table->text('conteudo');
            $table->timestamp('lido_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lido_em']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertas_internos');
    }
};
