<?php

namespace Database\Factories;

use App\Models\Boutique;
use App\Models\Categorie;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProduitFactory extends Factory
{
    public function definition(): array
    {
        // Le produit et sa catégorie doivent appartenir à la même boutique :
        // on crée la boutique une seule fois puis on l'attache aux deux.
        $boutique = Boutique::factory()->create();

        return [
            'boutique_id'  => $boutique->id,
            'nom'          => fake()->words(3, true),
            'sku'          => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'description'  => fake()->sentence(),
            'categorie_id' => Categorie::factory()->create(['boutique_id' => $boutique->id])->id,
            'prix_vente'   => fake()->randomFloat(2, 1000, 50000),
            'prix_achat'   => fake()->randomFloat(2, 500, 20000),
            'is_actif'     => true,
            'en_promo'     => false,
        ];
    }
}
