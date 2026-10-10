<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\CaisseSession;
use App\Models\Sortie;
use App\Models\Variante;
use App\Services\CreditService;
use App\Services\SortieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SortieController extends Controller
{
    use ApiResponse;

    public function __construct(private SortieService $sorties, private CreditService $credits) {}

    private function findOwned(Request $request, string $id): Sortie
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $sortie = Sortie::where('boutique_id', $boutiqueId)->find($id);
        if (!$sortie) throw new NotFoundException('Sortie introuvable', 'SORTIE_NOT_FOUND');
        return $sortie;
    }

    /**
     * @OA\Get(path="/sorties", tags={"Sorties"}, summary="Liste des sorties de sa boutique (paginée)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="type", in="query", @OA\Schema(type="string", enum={"VENTE","PERTE","DON","RETOUR_FOURNISSEUR","DEPENSE"})),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Sorties", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $q = Sortie::with(['user', 'boutique', 'lignes.variante.produit', 'transaction'])->where('boutique_id', $boutiqueId)->orderBy('created_at', 'desc');

        if ($request->filled('type'))      $q->where('type', $request->type);
        if ($request->filled('dateDebut')) $q->where('created_at', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))   $q->where('created_at', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $sortie = $this->findOwned($request, $id);
        return $this->success($sortie->load(['user', 'boutique', 'lignes.variante.produit', 'transaction']));
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
            'modeService'   => 'sometimes|nullable|in:' . implode(',', SortieService::MODES_SERVICE),
            'tableLabel'    => 'sometimes|nullable|string|max:30',
            'lignes'        => 'array|min:1|required_unless:type,DEPENSE',
            'lignes.*.varianteId'    => 'required|uuid',
            // Décimale pour les produits au poids ou au mètre ; SortieService exige un entier pour les pièces.
            'lignes.*.quantite'      => 'required|numeric|min:0.001',
            'lignes.*.prixUnitaire'  => 'required|numeric|min:0',
            // Vente à crédit (quincaillerie) : le client, l'échéance et un éventuel acompte.
            'clientId'       => 'sometimes|nullable|uuid',
            'echeanceJours'  => 'sometimes|nullable|integer|min:1|max:365',
            'acompteMontant' => 'sometimes|nullable|numeric|min:0',
            'acompteMode'    => 'sometimes|nullable|in:' . implode(',', CreditService::MODES_PAIEMENT),
            // Référence créée par l'appareil : si la réponse se perd, la vente renvoyée plus tard n'est pas doublée.
            'clientRef'      => 'sometimes|nullable|string|max:64',
        ]);

        $boutiqueId = $this->tenantBoutiqueId($request);
        $userId     = $request->user()->id;

        if (!empty($data['clientRef'])) {
            $deja = Sortie::where('boutique_id', $boutiqueId)->where('client_ref', $data['clientRef'])->first();
            if ($deja) return $this->success($deja->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']), 200);
        }

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

        $sortie = $this->sorties->creer($boutiqueId, $userId, $data['type'], $data['lignes'], [
            'remiseMontant' => $data['remiseMontant'] ?? null,
            'notes'         => $data['notes'] ?? null,
            'modeService'   => $data['modeService'] ?? null,
            'tableLabel'    => $data['tableLabel'] ?? null,
            'clientRef'     => $data['clientRef'] ?? null,
            'credit'        => empty($data['clientId']) ? null : [
                'clientId'       => $data['clientId'],
                'echeanceJours'  => $data['echeanceJours'] ?? null,
                'acompteMontant' => $data['acompteMontant'] ?? null,
                'acompteMode'    => $data['acompteMode'] ?? null,
            ],
        ]);

        return $this->success($sortie->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']), 201);
    }

    /** Vente faite hors connexion, envoyée au retour du réseau (rejouable sans doublon). */
    public function storeHorsLigne(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clientRef'     => 'required|string|max:64',
            'venduLe'       => 'required|date',
            'modePaiement'  => 'required|in:' . implode(',', CreditService::MODES_PAIEMENT),
            'montantPaye'   => 'sometimes|nullable|numeric|min:0',
            'remiseMontant' => 'sometimes|nullable|numeric|min:0',
            'notes'         => 'sometimes|nullable|string',
            'modeService'   => 'sometimes|nullable|in:' . implode(',', SortieService::MODES_SERVICE),
            'tableLabel'    => 'sometimes|nullable|string|max:30',
            'lignes'        => 'required|array|min:1',
            'lignes.*.varianteId'   => 'required|uuid',
            'lignes.*.quantite'     => 'required|numeric|min:0.001',
            'lignes.*.prixUnitaire' => 'required|numeric|min:0',
        ]);

        [$sortie, $creee] = $this->sorties->enregistrerHorsLigne(
            $this->tenantBoutiqueId($request),
            $request->user()->id,
            $data['lignes'],
            $data,
        );

        return $this->success($sortie->load(['lignes.variante.produit', 'user', 'transaction']), $creee ? 201 : 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $sortie = $this->findOwned($request, $id);

        $data = $request->validate(['notes' => 'sometimes|nullable|string']);
        $sortie->update($data);
        return $this->success($sortie->fresh()->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $sortie = $this->findOwned($request, $id)->load('lignes');

        $userId = $request->user()->id;
        DB::transaction(function () use ($sortie, $userId) {
            $this->sorties->remettreEnStock($sortie, $userId);
            // Vente supprimée : elle disparaît aussi du compte du client (un acompte versé reste acquis).
            \App\Models\OperationCredit::where('sortie_id', $sortie->id)->whereIn('type', ['VENTE', 'ANNULATION'])->delete();
            $sortie->delete();
        });

        \App\Models\AuditLog::record($userId, 'SORTIE_DESTROY', 'Sortie', $sortie->id, 'Suppression sortie ' . $sortie->reference);

        return $this->success($sortie);
    }

    public function annuler(Request $request, string $id): JsonResponse
    {
        $sortie = $this->findOwned($request, $id)->load('lignes');

        if (str_starts_with($sortie->notes ?? '', '[ANNULÉE]')) {
            throw new ConflictException('Sortie déjà annulée', 'SORTIE_ALREADY_CANCELLED');
        }

        $userId = $request->user()->id;
        DB::transaction(function () use ($sortie, $userId) {
            $this->sorties->remettreEnStock($sortie, $userId);
            $this->credits->annulerVente($sortie, $userId);
            $sortie->update(['notes' => '[ANNULÉE] ' . ($sortie->notes ?? '')]);
        });

        \App\Models\AuditLog::record($userId, 'SORTIE_ANNULER', 'Sortie', $sortie->id, 'Annulation sortie ' . $sortie->reference);

        return $this->success($sortie->fresh()->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']));
    }
}
