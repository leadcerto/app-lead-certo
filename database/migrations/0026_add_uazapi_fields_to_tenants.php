<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('uazapi_instance_name')->nullable()->after('whatsapp_connected_since');
            $table->string('uazapi_instance_token')->nullable()->after('uazapi_instance_name');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['uazapi_instance_name', 'uazapi_instance_token']);
        });
    }
};
