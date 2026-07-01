<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PilotoFreteSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['nicho' => 'frete'],
            [
                'nome'   => 'Frete Rio',
                'status' => 'ativo',
            ]
        );

        // Admin Lead Certo (sem tenant)
        User::firstOrCreate(
            ['email' => 'admin@leadcerto.com.br'],
            [
                'tenant_id' => null,
                'nome'      => 'Admin Lead Certo',
                'password'  => Hash::make('admin123'),
                'perfil'    => 'admin',
                'ativo'     => true,
            ]
        );

        // Vendedor do piloto frete
        User::firstOrCreate(
            ['email' => 'vendedor@frete.rio.br'],
            [
                'tenant_id' => $tenant->id,
                'nome'      => 'Leonardo',
                'password'  => Hash::make('frete123'),
                'perfil'    => 'vendedor',
                'ativo'     => true,
            ]
        );

        $this->command->info("Piloto Frete criado — tenant_id: {$tenant->id}");
        $this->command->info('Admin: admin@leadcerto.com.br / admin123');
        $this->command->info('Vendedor: vendedor@frete.rio.br / frete123');
    }
}
