<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Pivot: qual contato (via vínculo de tenant) tem qual etiqueta
        Schema::create('vinculo_etiqueta', function (Blueprint $table) {
            $table->foreignId('vinculo_id')
                ->constrained('vinculos_contato_tenant')
                ->cascadeOnDelete();
            $table->foreignId('etiqueta_id')
                ->constrained('etiquetas')
                ->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['vinculo_id', 'etiqueta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculo_etiqueta');
    }
};
