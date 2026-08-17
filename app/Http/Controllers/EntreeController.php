<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\Entree;
use App\Models\Fournisseur;

use App\Models\Produit;
use App\Models\Variante;

use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EntreeController extends Controller
{
    use ApiResponse;

    public function __construct(private StockMovementService $movements) {}

    private function boutiqueId(Request $request): ?string
    {
        $user = $request->user();
        return $user->role === 'ADMIN' ? ($request->query('boutiqueId') ?? null) : $user->boutique_id;
    }

    /**
     * @OA\Get(path="/entrees", tags={"Entrées"}, summary="Liste des entrées de stock (paginée)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="fournisseur", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Entrées", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $q = Entree::with(['user', 'boutique', 'lignes.variante.produit'])->orderBy('created_at', 'desc');

        if ($boutiqueId)                  $q->where('boutique_id', $boutiqueId);
        if ($request->filled('fournisseur')) $q->where('fournisseur', 'like', '%'.$request->fournisseur.'%');
        if ($request->filled('fournisseurId')) $q->where('fournisseur_id', $request->fournisseurId);
        if ($request->filled('dateDebut'))   $q->where('created_at', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))     $q->where('created_at', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    public function show(string $id): JsonResponse
    {
        $e = Entree::with(['user', 'boutique', 'lignes.variante.produit'])->find($id);
        if (!$e) throw new NotFoundException('Entrée introuvable', 'ENTREE_NOT_FOUND');
        return $this->success($e);
    }

    /**
     * @OA\Post(path="/entrees", tags={"Entrées"}, summary="Créer une entrée de stock", security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"fournisseur","lignes"},
     *             @OA\Property(property="fournisseur", type="string"),
     *             @OA\Property(property="notes", type="string", nullable=true),
     *             @OA\Property(property="lignes", type="array", @OA\Items(type="object",
     *                 @OA\Property(property="varianteId", type="string", format="uuid"),
     *                 @OA\Property(property="quantite", type="integer"),
     *                 @OA\Property(property="prixUnitaire", type="number")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Entrée créée", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fournisseur'  => 'required|string',
            'notes'        => 'sometimes|nullable|string',
            'dateOperation'=> 'sometimes|nullable|date',
            'lignes'       => 'required|array|min:1',
            'lignes.*.varianteId'       => 'sometimes|nullable|uuid',
            'lignes.*.quantite'         => 'required|integer|min:1',
            'lignes.*.prixUnitaire'     => 'required|numeric|min:0',
            'lignes.*.newProduit'       => 'sometimes|nullable|array',
        ]);

        $boutiqueId = $this->boutiqueId($request);
        $userId     = $request->user()->id;
        $reference  = 'ENT-' . strtoupper(Str::random(8));
        $totalCout  = '0.00';

        $fournisseurNom = trim($data['fournisseur']);
        $fournisseur = Fournisseur::firstOrCreate(
            ['nom' => $fournisseurNom],
            ['nom' => $fournisseurNom]
        );

        $entree = DB::transaction(function () use ($data, $boutiqueId, $userId, $reference, $fournisseur, &$totalCout) {
            $entree = Entree::create([
                'reference'      => $reference,
                'fournisseur'    => $data['fournisseur'],
                'fournisseur_id' => $fournisseur->id,
                'total_cout'     => '0.00',
                'notes'          => $data['notes'] ?? null,
                'user_id'        => $userId,
                'boutique_id'    => $boutiqueId,
            ]);

            foreach ($data['lignes'] as $ligne) {
                $varianteId = $ligne['varianteId'] ?? null;

                if (!$varianteId && !empty($ligne['newProduit'])) {
                    $np = $ligne['newProduit'];
                    $produit = Produit::create([
                        'nom'          => $np['nom'],
                        'sku'          => Str::slug($np['nom']) . '-' . base_convert((string) time(), 10, 36),
                        'categorie_id' => $np['categorieId'],
                        'prix_vente'   => $np['prixVente'],
                        'prix_achat'   => $np['prixAchat'],
                        'image_url'    => $np['imageUrl'] ?? null,
                    ]);
                    $variante = Variante::create([
                        'produit_id'     => $produit->id,
                        'boutique_id'    => $boutiqueId,
                        'taille'         => $np['taille'],
                        'couleur'        => $np['couleur'],
                        'quantite_stock' => 0,
                        'seuil_alerte'   => $np['seuilAlerte'] ?? 5,
                    ]);
                    $varianteId = $variante->id;
                }

                $entree->lignes()->create([
                    'variante_id'   => $varianteId,
                    'quantite'      => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prixUnitaire'],
                ]);

                $this->movements->create($varianteId, 'ENTREE', $ligne['quantite'], $userId, null, $reference);
                $totalCout = bcadd($totalCout, bcmul((string) $ligne['prixUnitaire'], (string) $ligne['quantite'], 2), 2);
            }

            $entree->update(['total_cout' => $totalCout]);
            return $entree;
        });

        return $this->success($entree->load(['lignes.variante.produit', 'user', 'boutique']), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $entree = Entree::find($id);
        if (!$entree) throw new NotFoundException('Entrée introuvable', 'ENTREE_NOT_FOUND');

        $data = $request->validate([
            'fournisseur' => 'sometimes|string',
            'notes'       => 'sometimes|nullable|string',
        ]);

        $entree->update($data);
        return $this->success($entree->fresh()->load(['lignes.variante.produit', 'user', 'boutique']));
    }

    public function destroy(string $id): JsonResponse
    {
        $entree = Entree::with('lignes')->find($id);
        if (!$entree) throw new NotFoundException('Entrée introuvable', 'ENTREE_NOT_FOUND');

        $userId = request()->user()->id;
        DB::transaction(function () use ($entree, $userId) {
            foreach ($entree->lignes as $ligne) {
                $this->movements->create($ligne->variante_id, 'SORTIE', $ligne->quantite, $userId, 'Annulation entrée ' . $entree->reference);
            }
            $entree->delete();
        });

        \App\Models\AuditLog::record($userId, 'ENTREE_DESTROY', 'Entree', $entree->id, 'Suppression entrée ' . $entree->reference);

        return $this->success($entree);
    }

    /**
     * @OA\Patch(path="/entrees/{id}/annuler", tags={"Entrées"}, summary="Annuler une entrée (reverse les stocks)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Annulée", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Déjà annulée", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function annuler(string $id): JsonResponse
    {
        $entree = Entree::with('lignes')->find($id);
        if (!$entree) throw new NotFoundException('Entrée introuvable', 'ENTREE_NOT_FOUND');

        if (str_starts_with($entree->fournisseur, '[ANNULÉE]')) {
            throw new \App\Exceptions\ConflictException('Entrée déjà annulée', 'ENTREE_ALREADY_CANCELLED');
        }

        $userId = request()->user()->id;
        DB::transaction(function () use ($entree, $userId) {
            foreach ($entree->lignes as $ligne) {
                $this->movements->create($ligne->variante_id, 'SORTIE', $ligne->quantite, $userId, 'Annulation entrée ' . $entree->reference);
            }
            $entree->update(['fournisseur' => '[ANNULÉE] ' . $entree->fournisseur]);
        });

        \App\Models\AuditLog::record($userId, 'ENTREE_ANNULER', 'Entree', $entree->id, 'Annulation entrée ' . $entree->reference);

        return $this->success($entree->fresh()->load(['lignes.variante.produit', 'user', 'boutique']));
    }
}
