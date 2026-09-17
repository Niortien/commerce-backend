<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\MouvementStock;
use App\Models\Variante;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    use ApiResponse;

    public function __construct(private StockMovementService $movements) {}

    /**
     * @OA\Get(path="/stock", tags={"Stock"}, summary="État du stock de sa boutique (paginé)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="categorieId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="taille", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="couleur", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer", default=1)),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Liste des variantes avec stock", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $boutiqueId = $this->resolveBoutiqueId($request);
        $q = Variante::with(['produit.categorie', 'boutique'])->where('boutique_id', $boutiqueId)->whereHas('produit');

        if ($request->filled('categorieId')) {
            $q->whereHas('produit', fn($p) => $p->where('categorie_id', $request->categorieId));
        }
        if ($request->filled('taille'))  $q->where('taille', $request->taille);
        if ($request->filled('couleur')) $q->where('couleur', $request->couleur);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    /**
     * @OA\Get(path="/stock/alertes", tags={"Stock"}, summary="Variantes sous le seuil d'alerte", security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Variantes en alerte", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function alertes(Request $request): JsonResponse
    {
        $boutiqueId = $this->resolveBoutiqueId($request);
        $q = Variante::with(['produit.categorie', 'boutique'])
            ->where('boutique_id', $boutiqueId)
            ->whereHas('produit')
            ->whereColumn('quantite_stock', '<=', 'seuil_alerte');

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    /**
     * @OA\Get(path="/stock/mouvements", tags={"Stock"}, summary="Historique des mouvements de stock de sa boutique", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"ENTREE","SORTIE","AJUSTEMENT","RETOUR"})),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Mouvements", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function mouvements(Request $request): JsonResponse
    {
        $boutiqueId = $this->resolveBoutiqueId($request);
        $q = MouvementStock::with(['variante.produit', 'user'])
            ->whereHas('variante', fn($v) => $v->where('boutique_id', $boutiqueId))
            ->orderBy('created_at', 'desc');

        if ($request->filled('type'))      $q->where('type', $request->type);
        if ($request->filled('produitId')) {
            $q->whereHas('variante', fn($v) => $v->where('produit_id', $request->produitId));
        }
        if ($request->filled('dateDebut')) $q->where('created_at', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))   $q->where('created_at', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }
}
