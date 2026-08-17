<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Upsert des 40 catégories canoniques.
 * INSERT … ON DUPLICATE KEY UPDATE garantit que le nom et la description
 * sont corrects même si la ligne existe déjà (sans toucher aux produits liés).
 */
return new class extends Migration
{
    public function up(): void
    {
        $catDefs = [
            // Hauts
            ['nom' => 'Tee-shirt',              'slug' => 'tee-shirt',              'description' => 'Hauts'],
            ['nom' => 'Polo',                   'slug' => 'polo',                   'description' => 'Hauts'],
            ['nom' => 'Polo corp',              'slug' => 'polo-corp',              'description' => 'Hauts'],
            ['nom' => 'Polo sans col',          'slug' => 'polo-sans-col',          'description' => 'Hauts'],
            ['nom' => 'Polo cardigan',          'slug' => 'polo-cardigan',          'description' => 'Hauts'],
            ['nom' => 'Déambré',                'slug' => 'deambre',                'description' => 'Hauts'],
            ['nom' => 'Débardeur',              'slug' => 'debardeur',              'description' => 'Hauts'],
            // Chemises & Vestes
            ['nom' => 'Chemise simple',         'slug' => 'chemise-simple',         'description' => 'Chemises & Vestes'],
            ['nom' => 'Chemise croppée',        'slug' => 'chemise-crope',          'description' => 'Chemises & Vestes'],
            ['nom' => 'Djaket',                 'slug' => 'djaket',                 'description' => 'Chemises & Vestes'],
            ['nom' => 'Doudoune',               'slug' => 'doudoune',               'description' => 'Chemises & Vestes'],
            // Tenues
            ['nom' => 'Complet-culotte',        'slug' => 'complet-culotte',        'description' => 'Tenues'],
            ['nom' => 'Complet-pantalon',       'slug' => 'complet-pantalon',       'description' => 'Tenues'],
            ['nom' => 'Complet-pull',           'slug' => 'complet-pull',           'description' => 'Tenues'],
            ['nom' => 'Complet sous-vêtement',  'slug' => 'complet-sous-vetement',  'description' => 'Tenues'],
            // Pulls & Maillots
            ['nom' => 'Pull simple',            'slug' => 'pull-simple',            'description' => 'Pulls & Maillots'],
            ['nom' => 'Pull cardigan',          'slug' => 'pull-cardigan',          'description' => 'Pulls & Maillots'],
            ['nom' => 'Maillot de foot',        'slug' => 'maillot-foot',           'description' => 'Pulls & Maillots'],
            ['nom' => 'Maillot de basket',      'slug' => 'maillot-basket',         'description' => 'Pulls & Maillots'],
            // Bas
            ['nom' => 'Pantalon tissu',         'slug' => 'pantalon-tissu',         'description' => 'Bas'],
            ['nom' => 'Pantalon docker',        'slug' => 'pantalon-docker',        'description' => 'Bas'],
            ['nom' => 'Jogging',                'slug' => 'jogging',                'description' => 'Bas'],
            ['nom' => 'Jean Simple',            'slug' => 'jean-simple',            'description' => 'Bas'],
            ['nom' => 'Cargo',                  'slug' => 'cargo',                  'description' => 'Bas'],
            // Culotte
            ['nom' => 'Culotte Simple',         'slug' => 'culotte-simple',         'description' => 'Culotte'],
            ['nom' => 'Culotte Away',           'slug' => 'culotte-away',           'description' => 'Culotte'],
            ['nom' => 'Culotte Jean',           'slug' => 'culotte-jean',           'description' => 'Culotte'],
            ['nom' => 'Pantacourt Asaké',       'slug' => 'pantacourt-asake',       'description' => 'Culotte'],
            // Chaussures
            ['nom' => 'Basket',                 'slug' => 'basket',                 'description' => 'Chaussures'],
            ['nom' => 'Barbouche',              'slug' => 'barbouche',              'description' => 'Chaussures'],
            ['nom' => 'Cross',                  'slug' => 'cross',                  'description' => 'Chaussures'],
            ['nom' => 'Soulier',                'slug' => 'soulier',                'description' => 'Chaussures'],
            ['nom' => 'Sandale',                'slug' => 'sandale',                'description' => 'Chaussures'],
            ['nom' => 'Claquette',              'slug' => 'claquette',              'description' => 'Chaussures'],
            // Sacs & Divers
            ['nom' => 'Sac',                    'slug' => 'sac',                    'description' => 'Sacs & Divers'],
            ['nom' => 'Chaussettes',            'slug' => 'chaussettes',            'description' => 'Sacs & Divers'],
            ['nom' => 'Chocoto',                'slug' => 'chocoto',                'description' => 'Sacs & Divers'],
            // Parfum & Bijoux
            ['nom' => 'Parfum',                 'slug' => 'parfum',                 'description' => 'Parfum & Bijoux'],
            ['nom' => 'Montre',                 'slug' => 'montre',                 'description' => 'Parfum & Bijoux'],
            ['nom' => 'Lunette',                'slug' => 'lunette',                'description' => 'Parfum & Bijoux'],
        ];

        foreach ($catDefs as $cat) {
            $existing = DB::table('categories')->where('slug', $cat['slug'])->first();
            if ($existing) {
                DB::table('categories')->where('slug', $cat['slug'])->update([
                    'nom'         => $cat['nom'],
                    'description' => $cat['description'],
                ]);
            } else {
                DB::table('categories')->insert([
                    'id'          => (string) Str::uuid(),
                    'nom'         => $cat['nom'],
                    'slug'        => $cat['slug'],
                    'description' => $cat['description'],
                ]);
            }
        }

        Cache::forget('categories.all');
    }

    public function down(): void
    {
        // Pas de rollback destructif : les produits référencent ces catégories.
    }
};
