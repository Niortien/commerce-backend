<?php

namespace Database\Factories;

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
}
