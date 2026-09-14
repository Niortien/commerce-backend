<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Categorie;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\ProduitImage;
use App\Models\Variante;
use App\Services\CloudinaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProduitController extends Controller
{
    use ApiResponse;

    public function __construct(private CloudinaryService $cloudinary) {}

    /**
     * Résout la boutique dont on affiche le catalogue public : le paramètre
     * boutiqueId (ou boutiqueSlug) explicite, sinon la boutique de
     * l'utilisateur connecté (vue "mon catalogue" côté ADMIN/CAISSIER).
     * Chaque boutique ayant désormais son propre catalogue isolé, aucune
     * valeur ne veut dire "aucun résultat" plutôt que "tout mélanger".
     */
    private function resolveBoutiqueId(Request $request): ?string
    {
        if ($request->filled('boutiqueSlug')) {
            return \App\Models\Boutique::where('slug', $request->boutiqueSlug)->value('id');
        }
        if ($request->filled('boutiqueId')) {
            return $request->boutiqueId;
        }
        return $request->user()?->boutique_id;
    }

    /**
     * @OA\Get(path="/categories", tags={"Produits"}, summary="Liste les catégories du catalogue d'une boutique",
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Catégories",
     *         @OA\JsonContent(@OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Categorie")),
     *             @OA\Property(property="meta", type="object", nullable=true), @OA\Property(property="timestamp", type="string", format="date-time"))
     *     )
     * )
     */
    public function categories(Request $request): JsonResponse
    {
        $boutiqueId = $this->resolveBoutiqueId($request);
        if (!$boutiqueId) return $this->success([]);

        $data = Cache::remember("categories.{$boutiqueId}", 3600, fn () => Categorie::where('boutique_id', $boutiqueId)->orderBy('nom')->get());
        return $this->success($data);
    }

    /**
     * @OA\Get(path="/produits", tags={"Produits"}, summary="Liste des produits d'une boutique (paginée)",
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="categorieId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Liste paginée des produits", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $boutiqueId = $this->resolveBoutiqueId($request);
        if (!$boutiqueId) return $this->paginated([], 0, 1, (int) $request->get('limit', 20));

        $q = Produit::with(['categorie', 'variantes', 'images'])->where('boutique_id', $boutiqueId)->where('is_actif', true);

        if ($request->filled('categorieId')) $q->where('categorie_id', $request->categorieId);
        if ($request->filled('search'))       $q->where('nom', 'like', '%' . $request->search . '%');
        if ($request->filled('enPromo'))      $q->where('en_promo', filter_var($request->enPromo, FILTER_VALIDATE_BOOLEAN));

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(200, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->orderBy('created_at', 'desc')->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    /**
     * @OA\Get(path="/produits/{id}", tags={"Produits"}, summary="Détail d'un produit",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Produit", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(string $id): JsonResponse
    {
        $p = Produit::with(['categorie', 'variantes', 'images'])->find($id);
        if (!$p) throw new NotFoundException('Produit introuvable', 'PRODUIT_NOT_FOUND');
        return $this->success($p);
    }

    /**
     * Charge un produit ET vérifie qu'il appartient bien à la boutique de
     * l'utilisateur courant (isolation stricte entre tenants).
     */
    private function findOwnedProduit(Request $request, string $id): Produit
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $produit = Produit::where('boutique_id', $boutiqueId)->find($id);
        if (!$produit) throw new NotFoundException('Produit introuvable', 'PRODUIT_NOT_FOUND');
        return $produit;
    }

    /**
     * @OA\Post(path="/produits", tags={"Produits"}, summary="Créer un produit dans le catalogue de sa boutique", security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"nom","categorieId","prixVente","prixAchat"},
     *             @OA\Property(property="nom", type="string"),
     *             @OA\Property(property="categorieId", type="string", format="uuid"),
     *             @OA\Property(property="prixVente", type="number"),
     *             @OA\Property(property="prixAchat", type="number"),
     *             @OA\Property(property="description", type="string", nullable=true),
     *             @OA\Property(property="imageUrl", type="string", nullable=true, description="URL ou base64"),
     *             @OA\Property(property="variantes", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="taille", type="string"),
     *                 @OA\Property(property="couleur", type="string"),
     *                 @OA\Property(property="quantiteStock", type="integer")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Produit créé", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);

        $data = $request->validate([
            'nom'         => 'required|string',
            'sku'         => 'sometimes|nullable|string',
            'description' => 'sometimes|nullable|string',
            'categorieId' => 'required|uuid',
            'prixVente'   => 'required|numeric|min:0',
            'prixAchat'   => 'required|numeric|min:0',
            'imageUrl'    => 'sometimes|nullable|string',
            'variantes'   => 'sometimes|array',
            'variantes.*.taille'         => 'required_with:variantes|string',
            'variantes.*.couleur'        => 'required_with:variantes|string',
            'variantes.*.quantiteStock'  => 'required_with:variantes|integer|min:0',
            'variantes.*.seuilAlerte'    => 'sometimes|integer|min:0',
        ]);

        $categorie = Categorie::where('boutique_id', $boutiqueId)->find($data['categorieId']);
        if (!$categorie) throw new NotFoundException('Categorie introuvable', 'CATEGORIE_NOT_FOUND');

        if (!empty($data['sku']) && Produit::where('boutique_id', $boutiqueId)->where('sku', $data['sku'])->exists()) {
            throw new \App\Exceptions\ConflictException('Ce SKU est déjà utilisé dans votre boutique', 'PRODUIT_SKU_TAKEN');
        }

        $imageUrl = $data['imageUrl'] ?? null;
        if ($imageUrl && str_starts_with($imageUrl, 'data:')) {
            try {
                $imageUrl = $this->cloudinary->uploadBase64($imageUrl);
            } catch (\RuntimeException $e) {
                throw new DomainException('IMAGE_UPLOAD_FAILED: ' . $e->getMessage(), 422, 'IMAGE_UPLOAD_FAILED');
            }
        }

        $sku = $data['sku'] ?? (Str::slug($data['nom']) . '-' . base_convert((string) time(), 10, 36));

        $produit = Produit::create([
            'boutique_id'  => $boutiqueId,
            'nom'          => $data['nom'],
            'sku'          => $sku,
            'description'  => $data['description'] ?? null,
            'categorie_id' => $data['categorieId'],
            'prix_vente'   => $data['prixVente'],
            'prix_achat'   => $data['prixAchat'],
            'image_url'    => $imageUrl,
        ]);

        if (!empty($data['variantes'])) {
            $now = now();
            $toInsert = [];
            foreach ($data['variantes'] as $v) {
                $toInsert[] = [
                    'id'             => (string) Str::uuid(),
                    'produit_id'     => $produit->id,
                    'boutique_id'    => $boutiqueId,
                    'taille'         => $v['taille'],
                    'couleur'        => $v['couleur'],
                    'quantite_stock' => $v['quantiteStock'],
                    'seuil_alerte'   => $v['seuilAlerte'] ?? 5,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
            Variante::insert($toInsert);
        }

        return $this->success($produit->load(['categorie', 'variantes', 'images']), 201);
    }

    /**
     * @OA\Patch(path="/produits/{id}", tags={"Produits"}, summary="Modifier un produit", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Mis à jour", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $produit = $this->findOwnedProduit($request, $id);

        $data = $request->validate([
            'nom'            => 'sometimes|string',
            'description'    => 'sometimes|nullable|string',
            'categorieId'    => 'sometimes|uuid',
            'prixVente'      => 'sometimes|numeric|min:0',
            'prixAchat'      => 'sometimes|numeric|min:0',
            'imageUrl'       => 'sometimes|nullable|string',
            'isActif'        => 'sometimes|boolean',
            'enPromo'        => 'sometimes|boolean',
            'prixPromo'      => 'sometimes|nullable|numeric|min:0',
            'dateDebutPromo' => 'sometimes|nullable|date',
            'dateFinPromo'   => 'sometimes|nullable|date',
        ]);

        if (isset($data['categorieId']) && !Categorie::where('boutique_id', $produit->boutique_id)->where('id', $data['categorieId'])->exists()) {
            throw new NotFoundException('Categorie introuvable', 'CATEGORIE_NOT_FOUND');
        }

        if (!empty($data['imageUrl']) && str_starts_with($data['imageUrl'], 'data:')) {
            try {
                $data['imageUrl'] = $this->cloudinary->uploadBase64($data['imageUrl']);
            } catch (\RuntimeException $e) {
                throw new DomainException('IMAGE_UPLOAD_FAILED: ' . $e->getMessage(), 422, 'IMAGE_UPLOAD_FAILED');
            }
        }

        $map = [
            'nom' => 'nom', 'description' => 'description', 'categorieId' => 'categorie_id',
            'prixVente' => 'prix_vente', 'prixAchat' => 'prix_achat', 'imageUrl' => 'image_url',
            'isActif' => 'is_actif', 'enPromo' => 'en_promo', 'prixPromo' => 'prix_promo',
            'dateDebutPromo' => 'date_debut_promo', 'dateFinPromo' => 'date_fin_promo',
        ];

        $update = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data)) $update[$to] = $data[$from];
        }

        if (array_key_exists('prix_vente', $update) && (string) $update['prix_vente'] !== (string) $produit->prix_vente
            || array_key_exists('prix_achat', $update) && (string) $update['prix_achat'] !== (string) $produit->prix_achat) {
            \App\Models\AuditLog::record(
                $request->user()->id,
                'PRODUIT_PRIX_UPDATE',
                'Produit',
                $produit->id,
                "Prix modifiés pour {$produit->nom} : vente {$produit->prix_vente} → " . ($update['prix_vente'] ?? $produit->prix_vente)
                    . ", achat {$produit->prix_achat} → " . ($update['prix_achat'] ?? $produit->prix_achat)
            );
        }

        $produit->update($update);
        return $this->success($produit->fresh()->load(['categorie', 'variantes', 'images']));
    }

    public function addVariante(Request $request, string $id): JsonResponse
    {
        $produit = $this->findOwnedProduit($request, $id);

        $data = $request->validate([
            'taille'        => 'required|string',
            'couleur'       => 'required|string',
            'quantiteStock' => 'sometimes|integer|min:0',
            'seuilAlerte'   => 'sometimes|integer|min:0',
        ]);

        $variante = Variante::create([
            'produit_id'     => $produit->id,
            'boutique_id'    => $produit->boutique_id,
            'taille'         => $data['taille'],
            'couleur'        => $data['couleur'],
            'quantite_stock' => $data['quantiteStock'] ?? 0,
            'seuil_alerte'   => $data['seuilAlerte'] ?? 5,
        ]);

        return $this->success($variante, 201);
    }

    /**
     * @OA\Delete(path="/produits/{id}", tags={"Produits"}, summary="Désactiver un produit", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Désactivé", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $produit = $this->findOwnedProduit($request, $id);
        $produit->delete();
        return $this->success(['message' => 'Produit supprimé', 'id' => $id]);
    }

    public function addImage(Request $request, string $id): JsonResponse
    {
        $produit = $this->findOwnedProduit($request, $id);

        $data = $request->validate(['url' => 'required|string']);

        if (str_starts_with($data['url'], 'data:')) {
            try {
                $url = $this->cloudinary->uploadBase64($data['url']);
            } catch (\RuntimeException $e) {
                abort(422, 'IMAGE_UPLOAD_FAILED: ' . $e->getMessage());
            }
        } else {
            $url = $data['url'];
        }

        $ordre = ProduitImage::where('produit_id', $produit->id)->max('ordre') + 1;
        $image = ProduitImage::create(['produit_id' => $produit->id, 'url' => $url, 'ordre' => $ordre]);

        return $this->success($image, 201);
    }

    public function removeImage(Request $request, string $id, string $imageId): JsonResponse
    {
        $produit = $this->findOwnedProduit($request, $id);
        $image = ProduitImage::where('id', $imageId)->where('produit_id', $produit->id)->first();
        if (!$image) throw new NotFoundException('Image introuvable', 'IMAGE_NOT_FOUND');
        $this->cloudinary->deleteByUrl($image->url);
        $image->delete();
        return $this->success($image);
    }

    public function mouvements(Request $request, string $id): JsonResponse
    {
        $produit = $this->findOwnedProduit($request, $id);

        $varianteIds = $produit->variantes()->pluck('id');
        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));

        $q     = MouvementStock::with(['variante', 'user'])->whereIn('variante_id', $varianteIds)->orderBy('created_at', 'desc');
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }
}
