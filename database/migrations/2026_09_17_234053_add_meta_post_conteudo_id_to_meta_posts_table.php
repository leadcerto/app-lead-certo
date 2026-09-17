<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            $table->foreignId('meta_post_conteudo_id')->nullable()->after('user_id')
                ->constrained('meta_post_conteudos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meta_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meta_post_conteudo_id');
        });
    }
};
