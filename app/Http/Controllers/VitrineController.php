<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\Produit;
use App\Models\Variante;
use Illuminate\Http\JsonResponse;

/**
 * Vitrine en ligne d'une boutique : page publique, sans compte, que le gérant partage
 * (bio Instagram, statut WhatsApp, QR code au comptoir).
 *
 * Seuls des champs choisis un par un sortent d'ici : jamais le prix d'achat, ni les
 * quantités exactes en stock. La vitrine n'existe que si la boutique l'a publiée et
 * que son accès est actif (essai ou abonnement en cours).
 */
class VitrineController extends Controller
{
    use ApiResponse;

    /** Au-delà, la vitrine affiche « Dernières pièces » plutôt que rien. */
    private const SEUIL_DERNIERES_PIECES = 3;

    public function show(string $slug): JsonResponse
    {
        $boutique = Boutique::where('slug', $slug)->first();
        if (!$boutique || !$boutique->vitrine_active || !$boutique->accesAutorise()) {
            throw new NotFoundException('Vitrine introuvable', 'VITRINE_NOT_FOUND');
        }

        // Les ingrédients d'un restaurant ne se vendent jamais seuls.
        $produits = Produit::with(['variantes', 'images'])
            ->where('boutique_id', $boutique->id)
            ->where('is_actif', true)
            ->where(fn ($q) => $q->whereNull('nature')->orWhere('nature', '!=', 'INGREDIENT'))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Produit $p) => $this->produitPublic($p))
            ->filter()
            ->values();

        $categorieIds = $produits->pluck('categorieId')->filter()->unique();
        $categories = Categorie::whereIn('id', $categorieIds)->orderBy('nom')->get(['id', 'nom'])
            ->map(fn (Categorie $c) => ['id' => $c->id, 'nom' => $c->nom])
            ->values();

        return $this->success([
            'boutique' => [
                'nom'          => $boutique->nom,
                'slug'         => $boutique->slug,
                'typeCommerce' => $boutique->type_commerce,
                'ville'        => $boutique->ville,
                'adresse'      => $boutique->adresse,
                'whatsapp'     => $boutique->whatsapp,
                'telephone'    => $boutique->telephone,
                'logoUrl'      => $boutique->logo_url,
            ],
            'categories' => $categories,
            'produits'   => $produits,
        ]);
    }

    /** Produit tel qu'un client peut le voir ; null s'il n'y a plus rien à vendre. */
    private function produitPublic(Produit $p): ?array
    {
        // Un plat se prépare à la commande : il n'a pas de stock propre.
        $estPlat = $p->nature === 'PLAT';

        $variantes = $p->variantes
            ->filter(fn (Variante $v) => $estPlat || $v->quantite_stock > 0)
            ->map(fn (Variante $v) => [
                'id'               => $v->id,
                'taille'           => $v->taille,
                'couleur'          => $v->couleur,
                'dernieresPieces'  => !$estPlat && $v->quantite_stock <= self::SEUIL_DERNIERES_PIECES,
            ])
            ->values();

        if ($variantes->isEmpty()) {
            return null;
        }

        $images = $p->images->sortBy('ordre')->pluck('url')->values()->all();
        if (!$images && $p->image_url) {
            $images = [$p->image_url];
        }

        $promoActive = $p->en_promo && $p->prix_promo !== null
            && (!$p->date_debut_promo || $p->date_debut_promo->isPast())
            && (!$p->date_fin_promo || $p->date_fin_promo->isFuture());

        return [
            'id'          => $p->id,
            'nom'         => $p->nom,
            'description' => $p->description,
            'categorieId' => $p->categorie_id,
            'unite'       => $p->unite,
            'prixVente'   => (float) $p->prix_vente,
            'prixPromo'   => $promoActive ? (float) $p->prix_promo : null,
            'images'      => $images,
            'variantes'   => $variantes,
        ];
    }
}
