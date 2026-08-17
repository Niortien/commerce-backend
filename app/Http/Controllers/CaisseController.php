<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Traits\ApiResponse;
use App\Models\CaisseSession;
use App\Models\Entree;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CaisseController extends Controller
{
    use ApiResponse;

    private function boutiqueId(Request $request, ?string $queryBoutiqueId = null): ?string
    {
        $user = $request->user();
        return $user->role === 'ADMIN' ? $queryBoutiqueId : $user->boutique_id;
    }

    /**
     * @OA\Get(path="/caisse/sessions", tags={"Caisse"}, summary="Liste des sessions de caisse", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Sessions", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function listSessions(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request, $request->query('boutiqueId'));
        $q = CaisseSession::with(['user', 'boutique'])->orderBy('date_ouverture', 'desc');
        if ($boutiqueId)                   $q->where('boutique_id', $boutiqueId);
        if ($request->filled('dateDebut')) $q->where('date_ouverture', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))   $q->where('date_ouverture', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    public function activeSession(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request, $request->query('boutiqueId'));
        $session = CaisseSession::with(['user', 'boutique'])
            ->where('statut', 'OUVERTE')
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->orderBy('date_ouverture', 'desc')
            ->first();

        return $this->success($session);
    }

    /**
     * @OA\Post(path="/caisse/sessions", tags={"Caisse"}, summary="Ouvrir une session de caisse", security={{"bearerAuth":{}}},
     *     @OA\RequestBody(@OA\JsonContent(@OA\Property(property="montantOuverture", type="number", example=0))),
     *     @OA\Response(response=201, description="Session ouverte", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Session déjà ouverte", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function openSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'montantOuverture' => 'sometimes|nullable|numeric|min:0',
        ]);

        $boutiqueId = $this->boutiqueId($request, $request->query('boutiqueId'));
        $userId     = $request->user()->id;

        $active = CaisseSession::where('statut', 'OUVERTE')
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->first();

        if ($active) {
            throw new ConflictException('Une session est déjà ouverte', 'SESSION_ALREADY_OPEN', ['sessionId' => $active->id]);
        }

        $session = CaisseSession::create([
            'user_id'           => $userId,
            'boutique_id'       => $boutiqueId,
            'date_ouverture'    => now(),
            'montant_ouverture' => $data['montantOuverture'] ?? '0',
            'statut'            => 'OUVERTE',
        ]);

        return $this->success($session->load(['user', 'boutique']), 201);
    }

    public function closeSession(Request $request, string $id): JsonResponse
    {
        $session = CaisseSession::with('transactions')->find($id);
        if (!$session) throw new NotFoundException('Session introuvable', 'SESSION_NOT_FOUND');
        if ($session->statut === 'FERMEE') {
            throw new ConflictException('La fermeture de caisse est irréversible', 'SESSION_CLOSED');
        }

        $data = $request->validate(['montantFermeture' => 'required|numeric|min:0']);

        // Écart = espèces déclarées à la fermeture vs. théorique (fond de caisse + ventes cash de la session).
        // Seules les transactions CASH sont comparables au tiroir physique (mobile money / carte n'y transitent pas).
        $totalCash = $session->transactions->where('mode_paiement', 'CASH')->sum('montant');
        $montantTheorique = bcadd((string) $session->montant_ouverture, (string) $totalCash, 2);
        $ecart = bcsub((string) $data['montantFermeture'], $montantTheorique, 2);

        $session->update([
            'statut'            => 'FERMEE',
            'date_fermeture'    => now(),
            'montant_fermeture' => $data['montantFermeture'],
            'montant_theorique' => $montantTheorique,
            'ecart'             => $ecart,
        ]);

        if (bccomp($ecart, '0', 2) !== 0) {
            \App\Models\AuditLog::record(
                $request->user()->id,
                'CAISSE_ECART',
                'CaisseSession',
                $session->id,
                "Écart de caisse à la fermeture : {$ecart} (théorique {$montantTheorique}, déclaré {$data['montantFermeture']})"
            );
        }

        return $this->success($session->fresh()->load('transactions'));
    }

    public function listTransactions(Request $request, string $id): JsonResponse
    {
        $q = Transaction::where('session_id', $id)->orderBy('created_at', 'desc');

        if ($request->filled('modePaiement')) $q->where('mode_paiement', $request->modePaiement);
        if ($request->filled('dateDebut'))    $q->where('created_at', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))      $q->where('created_at', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    /**
     * @OA\Post(path="/caisse/transactions", tags={"Caisse"}, summary="Enregistrer un paiement", security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"montant","modePaiement"},
     *             @OA\Property(property="montant", type="number"),
     *             @OA\Property(property="modePaiement", type="string", enum={"CASH","WAVE","ORANGE_MONEY","CARTE","MTN_MONEY"}),
     *             @OA\Property(property="sortieId", type="string", format="uuid", nullable=true),
     *             @OA\Property(property="reference", type="string", nullable=true),
     *             @OA\Property(property="notes", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Transaction créée", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Pas de session ouverte", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function createTransaction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'montant'      => 'required|numeric|min:0',
            'modePaiement' => 'required|in:CASH,WAVE,ORANGE_MONEY,CARTE,MTN_MONEY',
            'sortieId'     => 'sometimes|nullable|uuid',
            'reference'    => 'sometimes|nullable|string',
            'notes'        => 'sometimes|nullable|string',
        ]);

        $boutiqueId = $this->boutiqueId($request, $request->query('boutiqueId'));

        if (!$boutiqueId && $request->user()->role === 'ADMIN') {
            throw new ValidationException('boutiqueId requis pour un administrateur', 'BOUTIQUE_ID_REQUIRED');
        }

        $active = CaisseSession::where('statut', 'OUVERTE')
            ->where('boutique_id', $boutiqueId)
            ->orderBy('date_ouverture', 'desc')
            ->first();

        if (!$active) {
            throw new ConflictException('Aucune session de caisse ouverte', 'NO_ACTIVE_SESSION');
        }

        $transaction = Transaction::create([
            'session_id'    => $active->id,
            'sortie_id'     => $data['sortieId'] ?? null,
            'montant'       => $data['montant'],
            'mode_paiement' => $data['modePaiement'],
            'reference'     => $data['reference'] ?? null,
            'notes'         => $data['notes'] ?? null,
        ]);

        return $this->success($transaction, 201);
    }

    public function resumeJour(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request, $request->query('boutiqueId'));
        $todayStart = now()->startOfDay();

        $txQuery = fn($q) => $q
            ->where('transactions.created_at', '>=', $todayStart)
            ->when($boutiqueId, fn($q) => $q
                ->join('caisse_sessions as cs', 'transactions.session_id', '=', 'cs.id')
                ->where('cs.boutique_id', $boutiqueId)
            );

        $totaux = Transaction::query()
            ->tap($txQuery)
            ->selectRaw('COALESCE(SUM(montant), 0) as totalVentes, COUNT(*) as totalTransactions')
            ->first();

        $parMode = Transaction::query()
            ->tap($txQuery)
            ->selectRaw('mode_paiement, COALESCE(SUM(montant), 0) as total')
            ->groupBy('mode_paiement')
            ->pluck('total', 'mode_paiement')
            ->map(fn($v) => number_format((float) $v, 2, '.', ''))
            ->toArray();

        $totalAchats = (string) \Illuminate\Support\Facades\DB::table('entrees')
            ->where('created_at', '>=', $todayStart)
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->sum('total_cout');

        $totalDepenses = (string) \Illuminate\Support\Facades\DB::table('sorties')
            ->where('type', 'DEPENSE')
            ->where('created_at', '>=', $todayStart)
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->sum('total_montant');

        $totalVentes   = number_format((float) $totaux->totalVentes, 2, '.', '');
        $totalAchats   = number_format((float) $totalAchats, 2, '.', '');
        $totalDepenses = number_format((float) $totalDepenses, 2, '.', '');

        $session = CaisseSession::where('statut', 'OUVERTE')
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->orderBy('date_ouverture', 'desc')
            ->first();

        return $this->success([
            'session' => $session ? [
                'id'               => $session->id,
                'statut'           => $session->statut,
                'montantOuverture' => (string) $session->montant_ouverture,
                'dateOuverture'    => $session->date_ouverture->toISOString(),
            ] : null,
            'totalVentes'       => $totalVentes,
            'totalTransactions' => (int) $totaux->totalTransactions,
            'totalAchats'       => $totalAchats,
            'totalDepenses'     => $totalDepenses,
            'beneficeNet'       => number_format((float) $totalVentes - (float) $totalAchats, 2, '.', ''),
            'montantADeposer'   => number_format((float) $totalVentes - (float) $totalDepenses, 2, '.', ''),
            'parModePaiement'   => $parMode,
        ]);
    }
}
