<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\MouvementStock;
use App\Models\Variante;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;





class StockController extends Controller
{
    use ApiResponse;

    public function __construct(private StockMovementService $movements) {}

    /**
     * @OA\Get(path="/stock", tags={"Stock"}, summary="État du stock (paginé)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
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
        $q = Variante::with(['produit.categorie', 'boutique'])->whereHas('produit');

        if ($request->filled('categorieId')) {
            $q->whereHas('produit', fn($p) => $p->where('categorie_id', $request->categorieId));
        }
        if ($request->filled('boutiqueId')) $q->where('boutique_id', $request->boutiqueId);
        if ($request->filled('taille'))     $q->where('taille', $request->taille);
        if ($request->filled('couleur'))    $q->where('couleur', $request->couleur);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    /**
     * @OA\Get(path="/stock/alertes", tags={"Stock"}, summary="Variantes sous le seuil d'alerte", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Variantes en alerte", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function alertes(Request $request): JsonResponse
    {
        $q = Variante::with(['produit.categorie', 'boutique'])
            ->whereHas('produit')
            ->whereColumn('quantite_stock', '<=', 'seuil_alerte');

        if ($request->filled('boutiqueId')) $q->where('boutique_id', $request->boutiqueId);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    /**
     * @OA\Get(path="/stock/mouvements", tags={"Stock"}, summary="Historique de tous les mouvements de stock", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"ENTREE","SORTIE","AJUSTEMENT","RETOUR"})),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Mouvements", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function mouvements(Request $request): JsonResponse
    {
        $q = MouvementStock::with(['variante.produit', 'user'])->orderBy('created_at', 'desc');

        if ($request->filled('type'))      $q->where('type', $request->type);
        if ($request->filled('produitId')) {
            $q->whereHas('variante', fn($v) => $v->where('produit_id', $request->produitId));
        }
        if ($request->filled('boutiqueId')) {
            $q->whereHas('variante', fn($v) => $v->where('boutique_id', $request->boutiqueId));
        }
        if ($request->filled('dateDebut')) $q->where('created_at', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))   $q->where('created_at', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    /**
     * @OA\Post(path="/stock/transferts", tags={"Stock"}, summary="Transférer du stock d'une boutique à une autre", security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"varianteId","boutiqueDestinationId","quantite"},
     *             @OA\Property(property="varianteId", type="string", format="uuid"),
     *             @OA\Property(property="boutiqueDestinationId", type="string", format="uuid"),
     *             @OA\Property(property="quantite", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Transfert effectué", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Stock insuffisant", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function transferer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'varianteId'            => 'required|uuid|exists:variantes,id',
            'boutiqueDestinationId' => 'required|uuid|exists:boutiques,id',
            'quantite'              => 'required|integer|min:1',
        ]);

        $source = Variante::findOrFail($data['varianteId']);

        if ($source->boutique_id === $data['boutiqueDestinationId']) {
            throw new DomainException(
                'La boutique de destination doit être différente de la boutique source',
                422,
                'TRANSFERT_MEME_BOUTIQUE'
            );
        }

        $userId    = $request->user()->id;
        $reference = 'TRF-' . strtoupper(Str::random(8));

        $destination = DB::transaction(function () use ($source, $data, $userId, $reference) {
            $destination = Variante::firstOrCreate(
                [
                    'produit_id'  => $source->produit_id,
                    'boutique_id' => $data['boutiqueDestinationId'],
                    'taille'      => $source->taille,
                    'couleur'     => $source->couleur,
                ],
                [
                    'quantite_stock' => 0,
                    'seuil_alerte'   => $source->seuil_alerte,
                ]
            );

            $this->movements->create($source->id, 'SORTIE', $data['quantite'], $userId, "Transfert {$reference} vers boutique {$data['boutiqueDestinationId']}", null, $reference);
            $this->movements->create($destination->id, 'ENTREE', $data['quantite'], $userId, "Transfert {$reference} depuis boutique {$source->boutique_id}", $reference, null);

            return $destination;
        });

        return $this->success([
            'reference'   => $reference,
            'source'      => $source->fresh(),
            'destination' => $destination->fresh(),
        ], 201);
    }
}
