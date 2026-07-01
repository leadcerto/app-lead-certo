<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Mapeia cada etiqueta → grupo no Google Contacts de cada tenant
        Schema::create('etiqueta_google_grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etiqueta_id')->constrained('etiquetas')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Ex: "contactGroups/abc123def456"
            $table->string('google_group_resource_name');
            $table->timestamps();

            $table->unique(['etiqueta_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etiqueta_google_grupos');
    }
};
