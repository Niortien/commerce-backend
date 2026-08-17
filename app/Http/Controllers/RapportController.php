<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\Entree;
use App\Models\LigneSortie;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Sortie;
use App\Models\Transaction;
use App\Models\Variante;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class RapportController extends Controller
{
    use ApiResponse;

    private function boutiqueId(Request $request): ?string
    {
        $user = $request->user();
        return $user->role === 'ADMIN' ? ($request->query('boutiqueId') ?? null) : $user->boutique_id;
    }

    /**
     * @OA\Get(path="/rapports/ventes", tags={"Rapports"}, summary="Ventes groupées par période", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="groupBy", in="query", @OA\Schema(type="string", enum={"jour","semaine","mois"}, default="jour")),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Ventes par période", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function ventes(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $groupBy    = $request->get('groupBy', 'jour');
        $dateDebut  = $request->get('dateDebut', now()->subDays(30)->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $data = Cache::remember("ventes:{$boutiqueId}:{$groupBy}:{$dateDebut}:{$dateFin}", 300, function () use ($boutiqueId, $groupBy, $dateDebut, $dateFin) {
            $format = match ($groupBy) {
                'semaine' => '%Y-%u',
                'mois'    => '%Y-%m',
                default   => '%Y-%m-%d',
            };

            $q = Transaction::selectRaw("DATE_FORMAT(transactions.created_at, '{$format}') as periode, SUM(transactions.montant) as totalVentes, COUNT(*) as nombreTransactions")
                ->where('transactions.created_at', '>=', $dateDebut)
                ->where('transactions.created_at', '<=', $dateFin . ' 23:59:59');

            if ($boutiqueId) {
                $q->join('caisse_sessions as cs', 'transactions.session_id', '=', 'cs.id')
                  ->where('cs.boutique_id', $boutiqueId);
            }

            return $q->groupBy('periode')
                ->orderBy('periode')
                ->get()
                ->map(fn($row) => [
                    'periode'            => $row->periode,
                    'totalVentes'        => number_format((float) $row->totalVentes, 2, '.', ''),
                    'nombreTransactions' => (int) $row->nombreTransactions,
                    'nombreSorties'      => (int) $row->nombreTransactions,
                ]);
        });

        return $this->success($data);
    }

    public function stockValeur(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);

        $data = Cache::remember("stock-valeur:{$boutiqueId}", 300, function () use ($boutiqueId) {
            $row = \Illuminate\Support\Facades\DB::table('variantes as v')
                ->join('produits as p', 'v.produit_id', '=', 'p.id')
                ->where('p.is_actif', true)
                ->when($boutiqueId, fn($q) => $q->where('v.boutique_id', $boutiqueId))
                ->selectRaw('
                    COALESCE(SUM(v.quantite_stock * p.prix_achat), 0) as valeurAchat,
                    COALESCE(SUM(v.quantite_stock * p.prix_vente), 0) as valeurVente,
                    COUNT(DISTINCT v.produit_id) as nombreProduits,
                    COUNT(*) as nombreVariantes
                ')
                ->first();

            $valeurAchat = number_format((float) $row->valeurAchat, 2, '.', '');
            $valeurVente = number_format((float) $row->valeurVente, 2, '.', '');

            return [
                'valeurTotaleAchat'  => $valeurAchat,
                'valeurTotaleVente'  => $valeurVente,
                'beneficePotentiel'  => number_format((float) $valeurVente - (float) $valeurAchat, 2, '.', ''),
                'nombreVariantes'    => (int) $row->nombreVariantes,
                'nombreProduits'     => (int) $row->nombreProduits,
            ];
        });

        return $this->success($data);
    }

    public function topProduits(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $dateDebut  = $request->get('dateDebut', now()->subDays(30)->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $top = LigneSortie::selectRaw('
                ligne_sorties.variante_id,
                SUM(ligne_sorties.quantite) as totalVendu,
                SUM(ligne_sorties.quantite * ligne_sorties.prix_unitaire) as montantTotal
            ')
            ->join('sorties', 'sorties.id', '=', 'ligne_sorties.sortie_id')
            ->where('sorties.created_at', '>=', $dateDebut)
            ->where('sorties.created_at', '<=', $dateFin . ' 23:59:59')
            ->when($boutiqueId, fn($q) => $q->where('sorties.boutique_id', $boutiqueId))
            ->groupBy('ligne_sorties.variante_id')
            ->orderByDesc('totalVendu')
            ->limit(10)
            ->with('variante.produit')
            ->get();

        $result = $top->filter(fn($r) => $r->variante && $r->variante->produit)
            ->map(fn($r) => [
                'produitId'      => $r->variante->produit->id,
                'nom'            => $r->variante->produit->nom,
                'sku'            => $r->variante->produit->sku,
                'quantiteTotale' => (int) $r->totalVendu,
                'montantTotal'   => number_format((float) $r->montantTotal, 2, '.', ''),
            ])
            ->values();

        return $this->success($result);
    }

    /**
     * @OA\Get(path="/rapports/depenses", tags={"Rapports"}, summary="Total des depenses sur une periode", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Depenses", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function depenses(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $dateDebut  = $request->get('dateDebut', now()->subDays(30)->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $data = Cache::remember("depenses:{$boutiqueId}:{$dateDebut}:{$dateFin}", 300, function () use ($boutiqueId, $dateDebut, $dateFin) {
            $q = Sortie::where('type', 'DEPENSE')
                ->where('created_at', '>=', $dateDebut)
                ->where('created_at', '<=', $dateFin . ' 23:59:59')
                ->when($boutiqueId, fn($qq) => $qq->where('boutique_id', $boutiqueId));

            return [
                'totalDepenses'  => number_format((float) $q->clone()->sum('total_montant'), 2, '.', ''),
                'nombreDepenses' => (int) $q->clone()->count(),
            ];
        });

        return $this->success($data);
    }

    /**
     * @OA\Get(path="/rapports/recette-hebdomadaire", tags={"Rapports"}, summary="Recette nette (ventes - depenses) par semaine", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Recette par semaine", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function recetteHebdomadaire(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $dateDebut  = $request->get('dateDebut', now()->subWeeks(11)->startOfWeek()->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $data = Cache::remember("recette-hebdo:{$boutiqueId}:{$dateDebut}:{$dateFin}", 300, function () use ($boutiqueId, $dateDebut, $dateFin) {
            $format = '%Y-%u';

            $ventesQ = Transaction::selectRaw("DATE_FORMAT(transactions.created_at, '{$format}') as periode, SUM(transactions.montant) as total")
                ->where('transactions.created_at', '>=', $dateDebut)
                ->where('transactions.created_at', '<=', $dateFin . ' 23:59:59');
            if ($boutiqueId) {
                $ventesQ->join('caisse_sessions as cs', 'transactions.session_id', '=', 'cs.id')
                        ->where('cs.boutique_id', $boutiqueId);
            }
            $ventesRaw = $ventesQ->groupBy('periode')->pluck('total', 'periode')->toArray();

            $depensesRaw = Sortie::selectRaw("DATE_FORMAT(created_at, '{$format}') as periode, SUM(total_montant) as total")
                ->where('type', 'DEPENSE')
                ->where('created_at', '>=', $dateDebut)
                ->where('created_at', '<=', $dateFin . ' 23:59:59')
                ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
                ->groupBy('periode')
                ->pluck('total', 'periode')
                ->toArray();

            $periodes = array_unique(array_merge(array_keys($ventesRaw), array_keys($depensesRaw)));
            sort($periodes);

            return array_values(array_map(function ($periode) use ($ventesRaw, $depensesRaw) {
                $ventes   = (float) ($ventesRaw[$periode] ?? 0);
                $depenses = (float) ($depensesRaw[$periode] ?? 0);
                return [
                    'semaine'       => $periode,
                    'totalVentes'   => number_format($ventes, 2, '.', ''),
                    'totalDepenses' => number_format($depenses, 2, '.', ''),
                    'recetteNette'  => number_format($ventes - $depenses, 2, '.', ''),
                ];
            }, $periodes));
        });

        return $this->success($data);
    }

    public function fluxTresorerie(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $groupBy    = $request->get('groupBy', 'jour');
        $dateDebut  = $request->get('dateDebut', now()->subDays(30)->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $format = match ($groupBy) {
            'semaine' => '%Y-%u',
            'mois'    => '%Y-%m',
            default   => '%Y-%m-%d',
        };

        $entreesRaw = Entree::selectRaw("DATE_FORMAT(created_at, '{$format}') as periode, SUM(total_cout) as total")
            ->where('created_at', '>=', $dateDebut)
            ->where('created_at', '<=', $dateFin . ' 23:59:59')
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->groupBy('periode')
            ->pluck('total', 'periode')
            ->toArray();

        $sortiesQ = Transaction::selectRaw("DATE_FORMAT(transactions.created_at, '{$format}') as periode, SUM(transactions.montant) as total")
            ->where('transactions.created_at', '>=', $dateDebut)
            ->where('transactions.created_at', '<=', $dateFin . ' 23:59:59');
        if ($boutiqueId) {
            $sortiesQ->join('caisse_sessions as cs', 'transactions.session_id', '=', 'cs.id')
                     ->where('cs.boutique_id', $boutiqueId);
        }
        $sortiesRaw = $sortiesQ->groupBy('periode')->pluck('total', 'periode')->toArray();

        $periodes = array_unique(array_merge(array_keys($entreesRaw), array_keys($sortiesRaw)));
        sort($periodes);

        $result = array_map(function ($periode) use ($entreesRaw, $sortiesRaw) {
            $e = (float) ($entreesRaw[$periode] ?? 0);
            $s = (float) ($sortiesRaw[$periode] ?? 0);
            return [
                'periode' => $periode,
                'entrees' => number_format($e, 2, '.', ''),
                'sorties' => number_format($s, 2, '.', ''),
                'solde'   => number_format($s - $e, 2, '.', ''),
            ];
        }, $periodes);

        return $this->success(array_values($result));
    }

    /**
     * @OA\Get(path="/rapports/resume-dashboard", tags={"Rapports"}, summary="Résumé tableau de bord", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Dashboard",
     *         @OA\JsonContent(@OA\Property(property="data", type="object",
     *             @OA\Property(property="ventesAujourdhui", type="string"),
     *             @OA\Property(property="alertesStock", type="integer"),
     *             @OA\Property(property="produitsActifs", type="integer"),
     *             @OA\Property(property="topProduits", type="array", @OA\Items(type="object"))
     *         ), @OA\Property(property="meta", type="object", nullable=true), @OA\Property(property="timestamp", type="string", format="date-time"))
     *     )
     * )
     */
    public function resumeDashboard(Request $request): JsonResponse
    {
        $boutiqueId = $this->boutiqueId($request);
        $dateDebut  = $request->get('dateDebut', now()->subDays(6)->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $cacheKey = "dashboard:{$boutiqueId}:{$dateDebut}:{$dateFin}";
        $data     = Cache::remember($cacheKey, 300, function () use ($boutiqueId, $dateDebut, $dateFin) {
            return $this->buildDashboard($boutiqueId, $dateDebut, $dateFin);
        });

        return $this->success($data);
    }

    private function buildDashboard(?string $boutiqueId, string $dateDebut, string $dateFin): array
    {

        // Ventes groupées par jour sur la période
        $ventesQ = Transaction::selectRaw("DATE_FORMAT(transactions.created_at, '%Y-%m-%d') as periode, SUM(transactions.montant) as totalVentes, COUNT(*) as nombreTransactions")
            ->where('transactions.created_at', '>=', $dateDebut)
            ->where('transactions.created_at', '<=', $dateFin . ' 23:59:59');
        if ($boutiqueId) {
            $ventesQ->join('caisse_sessions as cs', 'transactions.session_id', '=', 'cs.id')
                    ->where('cs.boutique_id', $boutiqueId);
        }
        $ventesRows = $ventesQ->groupBy('periode')
            ->orderBy('periode')
            ->get()
            ->map(fn($r) => [
                'periode'       => $r->periode,
                'totalVentes'   => number_format((float) $r->totalVentes, 2, '.', ''),
                'nombreSorties' => (int) $r->nombreTransactions,
            ])
            ->values()
            ->toArray();

        // Top 5 produits — 7 derniers jours (basé sur lignes_sorties, pas mouvements)
        $topRaw = LigneSortie::selectRaw('
                ligne_sorties.variante_id,
                SUM(ligne_sorties.quantite) as totalVendu,
                SUM(ligne_sorties.quantite * ligne_sorties.prix_unitaire) as montantTotal
            ')
            ->join('sorties', 'sorties.id', '=', 'ligne_sorties.sortie_id')
            ->where('sorties.created_at', '>=', now()->subDays(6)->startOfDay())
            ->when($boutiqueId, fn($q) => $q->where('sorties.boutique_id', $boutiqueId))
            ->groupBy('ligne_sorties.variante_id')
            ->orderByDesc('totalVendu')
            ->limit(5)
            ->with('variante.produit')
            ->get();

        $topProduits = $topRaw->filter(fn($r) => $r->variante && $r->variante->produit)
            ->map(fn($r) => [
                'produitId'      => $r->variante->produit->id,
                'nom'            => $r->variante->produit->nom,
                'sku'            => $r->variante->produit->sku,
                'quantiteTotale' => (int) $r->totalVendu,
                'montantTotal'   => number_format((float) $r->montantTotal, 2, '.', ''),
            ])
            ->values()
            ->toArray();

        // Diagnostic — regroupé en 2 requêtes au lieu de 6
        $diagProduits = \Illuminate\Support\Facades\DB::table('produits as p')
            ->leftJoin('variantes as v', 'v.produit_id', '=', 'p.id')
            ->where('p.is_actif', true)
            ->when($boutiqueId, fn($q) => $q->where('v.boutique_id', $boutiqueId))
            ->selectRaw('COUNT(DISTINCT p.id) as totalProduits, COUNT(v.id) as totalVariantes')
            ->first();

        $diagCaisse = \Illuminate\Support\Facades\DB::table('caisse_sessions as cs')
            ->when($boutiqueId, fn($q) => $q->where('cs.boutique_id', $boutiqueId))
            ->leftJoin('transactions as t', 't.session_id', '=', 'cs.id')
            ->selectRaw("
                SUM(CASE WHEN cs.statut = 'OUVERTE' THEN 1 ELSE 0 END) as sessionsOuvertes,
                COUNT(t.id) as totalVentesAllTime,
                SUM(CASE WHEN t.created_at >= ? THEN 1 ELSE 0 END) as totalVentes7j
            ", [now()->subDays(6)->startOfDay()])
            ->first();

        $totalEntrees = \App\Models\Entree::when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))->count();

        $totalProduits   = (int) $diagProduits->totalProduits;
        $totalVariantes  = (int) $diagProduits->totalVariantes;
        $sessionsOuvertes  = (int) $diagCaisse->sessionsOuvertes;
        $totalVentesAllTime = (int) $diagCaisse->totalVentesAllTime;
        $totalVentes7j   = (int) $diagCaisse->totalVentes7j;

        return [
            'periode'     => ['debut' => $dateDebut, 'fin' => $dateFin],
            'ventes'      => $ventesRows,
            'topProduits' => $topProduits,
            'diagnostic'  => [
                'totalProduits'     => $totalProduits,
                'totalVariantes'    => $totalVariantes,
                'totalEntrees'      => $totalEntrees,
                'totalVentesAllTime' => $totalVentesAllTime,
                'totalVentes7j'     => $totalVentes7j,
                'sessionsOuvertes'  => $sessionsOuvertes,
                'hasData'           => $totalProduits > 0 || $totalEntrees > 0,
            ],
        ];
    }

    /**
     * @OA\Get(path="/rapports/export-excel", tags={"Rapports"}, summary="Export des ventes en Excel (.xlsx)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Fichier Excel", @OA\MediaType(mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"))
     * )
     */
    public function exportExcel(Request $request): mixed
    {
        $boutiqueId = $this->boutiqueId($request);
        $dateDebut  = $request->get('dateDebut', now()->subDays(30)->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $sorties = Sortie::with(['lignes.variante.produit', 'user'])
            ->where('created_at', '>=', $dateDebut)
            ->where('created_at', '<=', $dateFin . ' 23:59:59')
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->limit(2000)
            ->get();

        $rows = [['Référence', 'Type', 'Total', 'Date', 'Vendeur']];
        foreach ($sorties as $s) {
            $rows[] = [$s->reference, $s->type, $s->total_montant, $s->created_at->toDateTimeString(), $s->user->email];
        }

        $export = new class($rows) implements FromArray, WithHeadings {
            public function __construct(private array $rows) {}
            public function array(): array { return array_slice($this->rows, 1); }
            public function headings(): array { return $this->rows[0]; }
        };

        return Excel::download($export, 'rapport-ventes.xlsx');
    }

    /**
     * @OA\Get(path="/rapports/export-pdf", tags={"Rapports"}, summary="Export des ventes en PDF", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Fichier PDF", @OA\MediaType(mediaType="application/pdf"))
     * )
     */
    public function exportPdf(Request $request): Response
    {
        $boutiqueId = $this->boutiqueId($request);
        $dateDebut  = $request->get('dateDebut', now()->subDays(30)->toDateString());
        $dateFin    = $request->get('dateFin', now()->toDateString());

        $sorties = Sortie::with(['lignes.variante.produit', 'user'])
            ->where('created_at', '>=', $dateDebut)
            ->where('created_at', '<=', $dateFin . ' 23:59:59')
            ->when($boutiqueId, fn($q) => $q->where('boutique_id', $boutiqueId))
            ->limit(2000)
            ->get();

        $pdf = Pdf::loadView('rapports.ventes', [
            'sorties'   => $sorties,
            'dateDebut' => $dateDebut,
            'dateFin'   => $dateFin,
        ]);

        return $pdf->download('rapport-ventes.pdf');
    }
}
