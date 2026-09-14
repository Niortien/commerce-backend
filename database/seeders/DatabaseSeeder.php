<?php

namespace Database\Seeders;

use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private ?string $demoBoutiqueId = null;

    public function run(): void
    {
        $this->seedUsers();
        $this->seedCategories();
    }

    /**
     * Données de démo pour le développement local : une boutique-locataire
     * avec un ADMIN et un CAISSIER. En production, les vraies boutiques
     * naissent via l'inscription (auth/inscription-boutique) ou via le
     * Super Admin (voir SuperAdminSeeder pour le premier compte plateforme).
     */
    private function seedUsers(): void
    {
        $boutique = Boutique::firstOrCreate(
            ['slug' => 'boutique-demo'],
            ['nom' => 'Boutique Demo', 'is_active' => true, 'statut' => 'ACTIF']
        );
        $this->demoBoutiqueId = $boutique->id;

        $users = [
            ['email' => 'admin@shop.com',    'password' => 'StrongPass123!', 'role' => 'ADMIN'],
            ['email' => 'caissier@shop.com', 'password' => 'StrongPass123!', 'role' => 'CAISSIER'],
        ];

        foreach ($users as $u) {
            User::firstOrCreate(
                ['email' => $u['email']],
                ['password_hash' => Hash::make($u['password']), 'role' => $u['role'], 'boutique_id' => $boutique->id]
            );
        }
    }

    private function seedCategories(): void
    {
        $catDefs = [
            // Hauts
            ['nom' => 'Tee-shirt',             'slug' => 'tee-shirt',             'description' => 'Hauts'],
            ['nom' => 'Polo',                  'slug' => 'polo',                  'description' => 'Hauts'],
            ['nom' => 'Polo corp',             'slug' => 'polo-corp',             'description' => 'Hauts'],
            ['nom' => 'Polo sans col',         'slug' => 'polo-sans-col',         'description' => 'Hauts'],
            ['nom' => 'Polo cardigan',         'slug' => 'polo-cardigan',         'description' => 'Hauts'],
            ['nom' => 'Déambré',               'slug' => 'deambre',               'description' => 'Hauts'],
            ['nom' => 'Débardeur',             'slug' => 'debardeur',             'description' => 'Hauts'],
            // Chemises & Vestes
            ['nom' => 'Chemise simple',        'slug' => 'chemise-simple',        'description' => 'Chemises & Vestes'],
            ['nom' => 'Chemise croppée',       'slug' => 'chemise-crope',         'description' => 'Chemises & Vestes'],
            ['nom' => 'Djaket',                'slug' => 'djaket',                'description' => 'Chemises & Vestes'],
            ['nom' => 'Doudoune',              'slug' => 'doudoune',              'description' => 'Chemises & Vestes'],
            // Tenues
            ['nom' => 'Complet-culotte',       'slug' => 'complet-culotte',       'description' => 'Tenues'],
            ['nom' => 'Complet-pantalon',      'slug' => 'complet-pantalon',      'description' => 'Tenues'],
            ['nom' => 'Complet-pull',          'slug' => 'complet-pull',          'description' => 'Tenues'],
            ['nom' => 'Complet sous-vêtement', 'slug' => 'complet-sous-vetement', 'description' => 'Tenues'],
            // Pulls & Maillots
            ['nom' => 'Pull simple',           'slug' => 'pull-simple',           'description' => 'Pulls & Maillots'],
            ['nom' => 'Pull cardigan',         'slug' => 'pull-cardigan',         'description' => 'Pulls & Maillots'],
            ['nom' => 'Maillot de foot',       'slug' => 'maillot-foot',          'description' => 'Pulls & Maillots'],
            ['nom' => 'Maillot de basket',     'slug' => 'maillot-basket',        'description' => 'Pulls & Maillots'],
            // Bas
            ['nom' => 'Pantalon tissu',        'slug' => 'pantalon-tissu',        'description' => 'Bas'],
            ['nom' => 'Pantalon docker',       'slug' => 'pantalon-docker',       'description' => 'Bas'],
            ['nom' => 'Jogging',               'slug' => 'jogging',               'description' => 'Bas'],
            ['nom' => 'Jean Simple',           'slug' => 'jean-simple',           'description' => 'Bas'],
            ['nom' => 'Cargo',                 'slug' => 'cargo',                 'description' => 'Bas'],
            // Culotte
            ['nom' => 'Culotte Simple',        'slug' => 'culotte-simple',        'description' => 'Culotte'],
            ['nom' => 'Culotte Away',          'slug' => 'culotte-away',          'description' => 'Culotte'],
            ['nom' => 'Culotte Jean',          'slug' => 'culotte-jean',          'description' => 'Culotte'],
            ['nom' => 'Pantacourt Asaké',      'slug' => 'pantacourt-asake',      'description' => 'Culotte'],
            // Chaussures
            ['nom' => 'Basket',                'slug' => 'basket',                'description' => 'Chaussures'],
            ['nom' => 'Barbouche',             'slug' => 'barbouche',             'description' => 'Chaussures'],
            ['nom' => 'Cross',                 'slug' => 'cross',                 'description' => 'Chaussures'],
            ['nom' => 'Soulier',               'slug' => 'soulier',               'description' => 'Chaussures'],
            ['nom' => 'Sandale',               'slug' => 'sandale',               'description' => 'Chaussures'],
            ['nom' => 'Claquette',             'slug' => 'claquette',             'description' => 'Chaussures'],
            // Sacs & Divers
            ['nom' => 'Sac',                   'slug' => 'sac',                   'description' => 'Sacs & Divers'],
            ['nom' => 'Chaussettes',           'slug' => 'chaussettes',           'description' => 'Sacs & Divers'],
            ['nom' => 'Chocoto',               'slug' => 'chocoto',               'description' => 'Sacs & Divers'],
            // Parfum & Bijoux
            ['nom' => 'Parfum',                'slug' => 'parfum',                'description' => 'Parfum & Bijoux'],
            ['nom' => 'Montre',                'slug' => 'montre',                'description' => 'Parfum & Bijoux'],
            ['nom' => 'Lunette',               'slug' => 'lunette',               'description' => 'Parfum & Bijoux'],
        ];

        foreach ($catDefs as $cat) {
            Categorie::firstOrCreate(
                ['boutique_id' => $this->demoBoutiqueId, 'slug' => $cat['slug']],
                ['nom' => $cat['nom'], 'description' => $cat['description']]
            );
        }
    }
}
