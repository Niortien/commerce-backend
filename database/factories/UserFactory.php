<?php

namespace Database\Factories;

use App\Models\Boutique;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email'         => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role'          => 'CAISSIER',
            'boutique_id'   => Boutique::factory(),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'ADMIN']);
    }

    public function caissier(): static
    {
        return $this->state(['role' => 'CAISSIER']);
    }

    /** @deprecated Alias de caissier() conservé pour compatibilité avec les anciens tests. */
    public function vendeur(): static
    {
        return $this->caissier();
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => 'SUPER_ADMIN', 'boutique_id' => null]);
    }
}
