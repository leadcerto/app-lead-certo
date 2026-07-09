<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->text('seq_objetivo')->nullable()->after('objetivo');
            $table->text('ia_objetivo')->nullable()->after('seq_objetivo');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_coluna_configs', function (Blueprint $table) {
            $table->dropColumn(['seq_objetivo', 'ia_objetivo']);
        });
    }
};
