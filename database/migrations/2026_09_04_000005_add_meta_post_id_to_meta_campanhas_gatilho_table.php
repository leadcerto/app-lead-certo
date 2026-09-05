<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_campanhas_gatilho', function (Blueprint $table) {
            $table->foreignId('meta_post_id')->nullable()->after('id')
                ->constrained('meta_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meta_campanhas_gatilho', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meta_post_id');
        });
    }
};
