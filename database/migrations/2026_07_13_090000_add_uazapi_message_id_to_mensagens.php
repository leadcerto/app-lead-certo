<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->string('uazapi_message_id', 100)->nullable()->after('midia_url');
            $table->unique('uazapi_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->dropUnique(['uazapi_message_id']);
            $table->dropColumn('uazapi_message_id');
        });
    }
};
