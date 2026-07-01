<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vinculos_consumidor_tenant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumidor_id')->constrained('consumidores')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['consumidor_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculos_consumidor_tenant');
    }
};
