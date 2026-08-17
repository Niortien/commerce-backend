<?php

namespace Database\Factories;

use App\Models\Produit;
use Illuminate\Database\Eloquent\Factories\Factory;

class VarianteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'produit_id'      => Produit::factory(),
            'boutique_id'     => null,
            'taille'          => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'XXL']),
            'couleur'         => fake()->safeColorName(),
            'quantite_stock'  => fake()->numberBetween(0, 100),
            'seuil_alerte'    => 5,
        ];
    }

    public function enAlerte(): static
    {
        return $this->state(['quantite_stock' => 2, 'seuil_alerte' => 5]);
    }
}
