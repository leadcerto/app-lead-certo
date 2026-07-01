<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumidores', function (Blueprint $table) {
            $table->id();
            $table->string('telefone', 20)->unique();
            $table->string('nome', 150)->nullable();
            $table->string('origem', 50)->comment('whatsapp, gmb, site, mineracao');
            $table->boolean('opt_out')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumidores');
    }
};
