<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WhatsappCanal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappCanal>
 */
class WhatsappCanalFactory extends Factory
{
    protected $model = WhatsappCanal::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'tipo'      => 'nao_oficial',
            'provider'  => 'uazapi',
            // Testes que não são sobre aquecimento não deveriam precisar pensar
            // nisso — factory representa um canal já estabelecido (teto de regime),
            // não um dia zero. Testes específicos de aquecimento sobrescrevem.
            'perfil_aquecimento'      => 'protegido',
            'aquecimento_iniciado_em' => now()->subDays(30),
            'status'    => 'connected',
            'phone'     => '55' . $this->faker->numerify('###########'),
            'connected_since' => now(),
            'webhook_token'   => $this->faker->unique()->regexify('[A-Za-z0-9]{48}'),
            'config'    => ['instance_name' => $this->faker->slug(), 'instance_token' => $this->faker->uuid()],
        ];
    }
}
