<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Categorie;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Catégories proposées à une nouvelle boutique selon son type de commerce, pour ne pas démarrer
 * devant un catalogue vide. La boutique les renomme, les supprime ou en crée d'autres librement.
 * Les boutiques de vêtements gardent leur fonctionnement actuel (aucune catégorie imposée).
 */
class CategoriesDeDepart
{
    /** @var array<string, array<string, string[]>> type => groupe => catégories */
    private const MODELES = [
        'RESTAURANT' => [
            'Menu'      => ['Plats du jour', 'Grillades', 'Accompagnements', 'Desserts'],
            'Boissons'  => ['Boissons fraîches', 'Jus naturels'],
            'Cuisine'   => ['Viandes et poissons', 'Légumes et fruits', 'Épicerie et condiments'],
        ],
        'QUINCAILLERIE' => [
            'Électricité'  => ['Câbles et fils', 'Ampoules et lampes', 'Prises et interrupteurs'],
            'Plomberie'    => ['Tuyaux et raccords', 'Robinetterie'],
            'Construction' => ['Ciment et fer', 'Peinture'],
            'Outillage'    => ['Outils à main', 'Vis, clous et boulons'],
        ],
        'FRIPERIE' => [
            'Vêtements'   => ['Femme', 'Homme', 'Enfant'],
            'Accessoires' => ['Chaussures', 'Sacs'],
            'Maison'      => ['Linge de maison'],
        ],
    ];

    public function creer(Boutique $boutique): void
    {
        $modele = self::MODELES[$boutique->type_commerce] ?? null;
        if (!$modele) return;

        foreach ($modele as $groupe => $noms) {
            foreach ($noms as $nom) {
                Categorie::firstOrCreate(
                    ['boutique_id' => $boutique->id, 'slug' => Str::slug($nom)],
                    ['nom' => $nom, 'description' => $groupe]
                );
            }
        }
        Cache::forget("categories.{$boutique->id}");
    }
}
