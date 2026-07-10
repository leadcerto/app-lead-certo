<?php

namespace Database\Factories;

use App\Models\Contato;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContatoFactory extends Factory
{
    protected $model = Contato::class;

    public function definition(): array
    {
        return [
            'telefone' => '55119' . fake()->unique()->numerify('########'),
            'nome'     => fake()->name(),
            'origem'   => 'whatsapp',
        ];
    }
}
