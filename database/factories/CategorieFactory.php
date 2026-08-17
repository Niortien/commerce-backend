<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategorieFactory extends Factory
{
    private static array $groupes = [
        'Hauts', 'Chemises & Vestes', 'Tenues', 'Pulls & Maillots',
        'Bas', 'Culotte', 'Chaussures', 'Sacs & Divers', 'Parfum & Bijoux',
    ];

    public function definition(): array
    {
        $nom = fake()->unique()->words(2, true);
        return [
            'nom'         => $nom,
            'slug'        => Str::slug($nom),
            'description' => fake()->randomElement(self::$groupes),
        ];
    }
}
