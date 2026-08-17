<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email'       => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role'        => 'VENDEUR',
            'boutique_id' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'ADMIN']);
    }

    public function vendeur(): static
    {
        return $this->state(['role' => 'VENDEUR']);
    }
}
