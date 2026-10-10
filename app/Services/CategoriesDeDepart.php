<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\Categorie;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Catégories proposées à une nouvelle boutique selon son type de commerce, pour ne pas démarrer
 * devant un catalogue vide. La boutique les renomme, les supprime ou en crée d'autres librement.
 * Chaque type de commerce a les siennes ; une valeur inconnue (ancien « MODE ») prend celles des vêtements.
 */
class CategoriesDeDepart
{
    /** @var array<string, array<string, string[]>> type => groupe => catégories */
    private const MODELES = [
        'VETEMENTS' => [
            'Hauts'              => ['T-shirts', 'Polos', 'Tops'],
            'Chemises & Vestes'  => ['Chemises', 'Vestes'],
            'Tenues'             => ['Robes', 'Ensembles', 'Tenues traditionnelles'],
            'Bas'                => ['Pantalons', 'Jeans', 'Jupes', 'Shorts'],
            'Chaussures'         => ['Chaussures'],
            'Sacs & Divers'      => ['Sacs', 'Ceintures et accessoires'],
            'Parfum & Bijoux'    => ['Parfums', 'Bijoux'],
        ],
        'CHAUSSURES' => [
            'Chaussures'  => ['Femme', 'Homme', 'Enfant', 'Sport'],
            'Sacs'        => ['Sacs à main', 'Sacs à dos'],
            'Accessoires' => ['Ceintures', 'Entretien'],
        ],
        'ALIMENTATION' => [
            'Épicerie'       => ['Riz et céréales', 'Huiles', 'Conserves', 'Sucre et farine'],
            'Boissons'       => ['Eaux', 'Sodas et jus'],
            'Produits frais' => ['Fruits et légumes', 'Produits laitiers'],
            'Snacks'         => ['Biscuits et bonbons'],
        ],
        'SUPERMARCHE' => [
            'Alimentaire'         => ['Épicerie salée', 'Épicerie sucrée', 'Conserves'],
            'Boissons'            => ['Eaux', 'Sodas et jus', 'Boissons chaudes'],
            'Frais'               => ['Produits laitiers', 'Fruits et légumes'],
            'Hygiène & entretien' => ['Hygiène corporelle', 'Entretien de la maison'],
            'Maison'              => ['Ustensiles'],
        ],
        'PHARMACIE' => [
            'Médicaments'      => ['Douleur et fièvre', 'Rhume et allergie', 'Digestion', 'Vitamines'],
            'Parapharmacie'    => ['Soins de la peau', 'Bébé et maman'],
            'Hygiène'          => ['Hygiène bucco-dentaire', 'Hygiène intime'],
            'Matériel médical' => ['Pansements', 'Tensiomètres et thermomètres'],
        ],
        'ELECTRONIQUE' => [
            'Téléphones'     => ['Smartphones', 'Téléphones simples'],
            'Ordinateurs'    => ['Ordinateurs portables', 'Tablettes'],
            'Accessoires'    => ['Chargeurs et câbles', 'Coques et protections', 'Cartes mémoire'],
            'Audio'          => ['Écouteurs', 'Enceintes'],
            'Électroménager' => ['Petit électroménager'],
        ],
        'BEAUTE' => [
            'Soins'      => ['Visage', 'Corps', 'Beurres et huiles'],
            'Maquillage' => ['Teint', 'Yeux et lèvres'],
            'Cheveux'    => ['Shampooings et soins', 'Mèches et perruques'],
            'Parfums'    => ['Parfums'],
        ],
        'AUTRE' => [
            'Général' => ['Articles courants', 'Nouveautés', 'Divers'],
        ],
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
        $modele = self::MODELES[$boutique->type_commerce] ?? self::MODELES['VETEMENTS'];

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
