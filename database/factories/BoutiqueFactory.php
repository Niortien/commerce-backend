<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BoutiqueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nom'      => fake()->company(),
            'adresse'  => fake()->streetAddress(),
            'ville'    => fake()->city(),
            'whatsapp' => fake()->phoneNumber(),
        ];
    }
}
