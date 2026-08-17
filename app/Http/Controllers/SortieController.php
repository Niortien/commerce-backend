<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\CaisseSession;
use App\Models\Sortie;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SortieController extends Controller
{
    use ApiResponse;

    public function __construct(private StockMovementService $movements) {}

    private function boutiqueId(Request $request): ?string
    {
        $user = $request->user();
        return $user->role === 'ADMIN' ? ($request->query('boutiqueId') ?? null) : $user->boutique_id;
    }

    /**
     * @OA\Get(path="/sorties", tags={"Sorties"}, summary="Liste des sorties (paginée)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"VENTE","PERTE","DON","RETOUR_FOURNISSEUR","DEPENSE"})),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Sorties", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $q = Sortie::with(['user', 'boutique', 'lignes.variante.produit', 'transaction'])->orderBy('created_at', 'desc');

        if ($boutiqueId)             $q->where('boutique_id', $boutiqueId);
        if ($request->filled('type'))      $q->where('type', $request->type);
        if ($request->filled('dateDebut')) $q->where('created_at', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))   $q->where('created_at', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    public function show(string $id): JsonResponse
    {
        $s = Sortie::with(['user', 'boutique', 'lignes.variante.produit', 'transaction'])->find($id);
        if (!$s) throw new NotFoundException('Sortie introuvable', 'SORTIE_NOT_FOUND');
        return $this->success($s);
    }

    /**
     * @OA\Post(path="/sorties", tags={"Sorties"}, summary="Créer une sortie / vente / dépense", security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"type"},
     *             @OA\Property(property="type", type="string", enum={"VENTE","PERTE","DON","RETOUR_FOURNISSEUR","DEPENSE"}),
     *             @OA\Property(property="remiseMontant", type="number", nullable=true),
     *             @OA\Property(property="notes", type="string", nullable=true, description="Description de la dépense (requis si type=DEPENSE)"),
     *             @OA\Property(property="montant", type="number", nullable=true, description="Montant de la dépense (requis si type=DEPENSE)"),
     *             @OA\Property(property="lignes", type="array", nullable=true, description="Requis sauf si type=DEPENSE", @OA\Items(type="object",
     *                 @OA\Property(property="varianteId", type="string", format="uuid"),
     *                 @OA\Property(property="quantite", type="integer"),
     *                 @OA\Property(property="prixUnitaire", type="number")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Sortie créée", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Pas de session caisse ouverte / stock insuffisant", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'          => 'required|in:VENTE,PERTE,DON,RETOUR_FOURNISSEUR,DEPENSE',
            'notes'         => 'nullable|string|required_if:type,DEPENSE',
            'montant'       => 'nullable|numeric|min:0.01|required_if:type,DEPENSE',
            'remiseMontant' => 'sometimes|nullable|numeric|min:0',
            'dateOperation' => 'sometimes|nullable|date',
            'lignes'        => 'array|min:1|required_unless:type,DEPENSE',
            'lignes.*.varianteId'    => 'required|uuid',
            'lignes.*.quantite'      => 'required|integer|min:1',
            'lignes.*.prixUnitaire'  => 'required|numeric|min:0',
        ]);

        $boutiqueId = $this->boutiqueId($request);
        $userId     = $request->user()->id;

        if ($data['type'] === 'DEPENSE') {
            $sortie = Sortie::create([
                'reference'          => 'SRT-' . strtoupper(Str::random(8)),
                'type'               => 'DEPENSE',
                'total_avant_remise' => null,
                'remise_montant'     => null,
                'total_montant'      => number_format((float) $data['montant'], 2, '.', ''),
                'notes'              => $data['notes'],
                'user_id'            => $userId,
                'boutique_id'        => $boutiqueId,
            ]);

            return $this->success($sortie->load(['user', 'boutique']), 201);
        }

        if ($data['type'] === 'VENTE') {
            $session = CaisseSession::where('statut', 'OUVERTE')
                ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
                ->first();
            if (!$session) {
                throw new ConflictException('Aucune session de caisse ouverte', 'NO_ACTIVE_SESSION');
            }
        }

        $totalAvant = '0.00';
        foreach ($data['lignes'] as $l) {
            $totalAvant = bcadd($totalAvant, bcmul((string) $l['prixUnitaire'], (string) $l['quantite'], 2), 2);
        }
        $remise = (string) ($data['remiseMontant'] ?? '0');
        $totalMontant = bcsub($totalAvant, $remise, 2);

        if (bccomp($totalMontant, '0', 2) < 0) {
            throw new \App\Exceptions\ValidationException('La remise dépasse le total', 'REMISE_INVALIDE');
        }

        $reference = 'SRT-' . strtoupper(Str::random(8));

        $sortie = DB::transaction(function () use ($data, $boutiqueId, $userId, $reference, $totalAvant, $remise, $totalMontant) {
            $sortie = Sortie::create([
                'reference'          => $reference,
                'type'               => $data['type'],
                'total_avant_remise' => $totalAvant,
                'remise_montant'     => $remise,
                'total_montant'      => $totalMontant,
                'notes'              => $data['notes'] ?? null,
                'user_id'            => $userId,
                'boutique_id'        => $boutiqueId,
            ]);

            foreach ($data['lignes'] as $ligne) {
                $sortie->lignes()->create([
                    'variante_id'   => $ligne['varianteId'],
                    'quantite'      => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prixUnitaire'],
                ]);
                $this->movements->create($ligne['varianteId'], 'SORTIE', $ligne['quantite'], $userId, null, null, $reference);
            }

            return $sortie;
        });

        return $this->success($sortie->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $sortie = Sortie::find($id);
        if (!$sortie) throw new NotFoundException('Sortie introuvable', 'SORTIE_NOT_FOUND');

        $data = $request->validate(['notes' => 'sometimes|nullable|string']);
        $sortie->update($data);
        return $this->success($sortie->fresh()->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']));
    }

    public function destroy(string $id): JsonResponse
    {
        $sortie = Sortie::with('lignes')->find($id);
        if (!$sortie) throw new NotFoundException('Sortie introuvable', 'SORTIE_NOT_FOUND');

        $userId = request()->user()->id;
        DB::transaction(function () use ($sortie, $userId) {
            foreach ($sortie->lignes as $ligne) {
                $this->movements->create($ligne->variante_id, 'RETOUR', $ligne->quantite, $userId, 'Annulation sortie ' . $sortie->reference);
            }
            $sortie->delete();
        });

        \App\Models\AuditLog::record($userId, 'SORTIE_DESTROY', 'Sortie', $sortie->id, 'Suppression sortie ' . $sortie->reference);

        return $this->success($sortie);
    }

    public function annuler(string $id): JsonResponse
    {
        $sortie = Sortie::with('lignes')->find($id);
        if (!$sortie) throw new NotFoundException('Sortie introuvable', 'SORTIE_NOT_FOUND');

        if (str_starts_with($sortie->notes ?? '', '[ANNULÉE]')) {
            throw new ConflictException('Sortie déjà annulée', 'SORTIE_ALREADY_CANCELLED');
        }

        $userId = request()->user()->id;
        DB::transaction(function () use ($sortie, $userId) {
            foreach ($sortie->lignes as $ligne) {
                $this->movements->create($ligne->variante_id, 'RETOUR', $ligne->quantite, $userId, 'Annulation sortie ' . $sortie->reference);
            }
            $sortie->update(['notes' => '[ANNULÉE] ' . ($sortie->notes ?? '')]);
        });

        \App\Models\AuditLog::record($userId, 'SORTIE_ANNULER', 'Sortie', $sortie->id, 'Annulation sortie ' . $sortie->reference);

        return $this->success($sortie->fresh()->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']));
    }
}
