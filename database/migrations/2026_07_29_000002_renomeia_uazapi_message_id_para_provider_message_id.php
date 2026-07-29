<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->renameColumn('uazapi_message_id', 'provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->renameColumn('provider_message_id', 'uazapi_message_id');
        });
    }
};
