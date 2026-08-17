<?php

namespace Database\Factories;

use App\Models\Categorie;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProduitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nom'          => fake()->words(3, true),
            'sku'          => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'description'  => fake()->sentence(),
            'categorie_id' => Categorie::factory(),
            'prix_vente'   => fake()->randomFloat(2, 1000, 50000),
            'prix_achat'   => fake()->randomFloat(2, 500, 20000),
            'is_actif'     => true,
            'en_promo'     => false,
        ];
    }
}
