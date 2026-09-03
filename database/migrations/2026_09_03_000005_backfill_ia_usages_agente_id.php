<?php

use App\Models\Cargo;
use App\Models\User;
use App\Services\AgenteIaResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $nathanel = User::where('email', 'like', '%nathanel%')->where('is_ia', true)->value('id')
            ?? User::where('is_ia', true)->value('id');

        if (! $nathanel) {
            return;
        }

        // Atribui registros históricos sem agente_id
        $usages = DB::table('ia_usages')->whereNull('agente_id')->get();

        foreach ($usages as $usage) {
            $agenteId = AgenteIaResolver::resolverAgenteId($usage->origem, $usage->tenant_id) ?? $nathanel;

            DB::table('ia_usages')
                ->where('id', $usage->id)
                ->update(['agente_id' => $agenteId]);
        }
    }

    public function down(): void
    {
        // No-op
    }
};
