<?php

namespace Database\Factories;

use App\Models\Abonnement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BoutiqueFactory extends Factory
{
    public function definition(): array
    {
        $nom = fake()->unique()->company();
        return [
            'nom'       => $nom,
            'slug'      => Str::slug($nom) . '-' . fake()->unique()->numberBetween(1000, 999999),
            'adresse'   => fake()->streetAddress(),
            'ville'     => fake()->city(),
            'whatsapp'  => fake()->phoneNumber(),
            'is_active' => true,
            'statut'    => 'ACTIF',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($boutique) {
            Abonnement::factory()->create(['boutique_id' => $boutique->id]);
        });
    }
}
