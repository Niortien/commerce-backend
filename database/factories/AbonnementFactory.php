<?php

namespace Database\Factories;

use App\Models\Boutique;
use Illuminate\Database\Eloquent\Factories\Factory;

class AbonnementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'boutique_id' => Boutique::factory(),
            'plan'        => 'ANNUEL',
            'statut'      => 'ACTIF',
            'date_debut'  => now(),
            'date_fin'    => now()->addYears(10),
            'montant'     => null,
            'devise'      => 'XOF',
            'notes'       => null,
        ];
    }
}
