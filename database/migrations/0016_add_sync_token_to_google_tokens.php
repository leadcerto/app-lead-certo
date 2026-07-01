<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_tokens', function (Blueprint $table) {
            // Sync Token — apenas delta desde o último sync (evita puxar 19k contatos a cada 6h)
            $table->text('sync_token')->nullable()->after('scopes');
            $table->timestamp('ultima_sync_em')->nullable()->after('sync_token');
        });
    }

    public function down(): void
    {
        Schema::table('google_tokens', function (Blueprint $table) {
            $table->dropColumn(['sync_token', 'ultima_sync_em']);
        });
    }
};
