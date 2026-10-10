<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Traits\ApiResponse;
use App\Models\Devis;
use App\Models\Variante;
use App\Services\SortieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Devis / factures proforma (quincaillerie). Un devis fixe des prix pour un client sans toucher au stock ;
 * accepté, il se convertit en vente (le stock sort à ce moment-là, caisse ouverte).
 */
class DevisController extends Controller
{
    use ApiResponse;

    private const RELATIONS = ['lignes.variante.produit', 'user', 'boutique', 'sortie'];

    public function __construct(private SortieService $sorties) {}

    private function findOwned(Request $request, string $id): Devis
    {
        $devis = Devis::where('boutique_id', $this->tenantBoutiqueId($request))->find($id);
        if (!$devis) throw new NotFoundException('Devis introuvable', 'DEVIS_NOT_FOUND');
        return $devis;
    }

    public function index(Request $request): JsonResponse
    {
        $q = Devis::with(['lignes', 'user'])
            ->where('boutique_id', $this->tenantBoutiqueId($request))
            ->orderBy('created_at', 'desc');

        if ($request->filled('statut')) $q->where('statut', $request->statut);
        if ($request->filled('search')) $q->where('client_nom', 'like', '%' . $request->search . '%');

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->success($this->findOwned($request, $id)->load(self::RELATIONS));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clientNom'          => 'required|string|max:150',
            'clientTelephone'    => 'sometimes|nullable|string|max:40',
            'validiteJours'      => 'sometimes|integer|min:1|max:365',
            'remiseMontant'      => 'sometimes|nullable|numeric|min:0',
            'notes'              => 'sometimes|nullable|string',
            'lignes'             => 'required|array|min:1',
            'lignes.*.varianteId'   => 'required|uuid',
            'lignes.*.quantite'     => 'required|numeric|min:0.001',
            'lignes.*.prixUnitaire' => 'required|numeric|min:0',
        ]);

        $boutiqueId = $this->tenantBoutiqueId($request);
        $variantes = Variante::with('produit')
            ->where('boutique_id', $boutiqueId)
            ->whereIn('id', array_column($data['lignes'], 'varianteId'))
            ->get()
            ->keyBy('id');

        $totalAvant = '0.00';
        foreach ($data['lignes'] as $l) {
            $variante = $variantes->get($l['varianteId']);
            if (!$variante || !$variante->produit) throw new NotFoundException('Article introuvable', 'VARIANTE_NOT_FOUND');
            if ($variante->produit->seVendALUnite() && floor((float) $l['quantite']) != (float) $l['quantite']) {
                throw new ValidationException("« {$variante->produit->nom} » se vend à l'unité : la quantité doit être un nombre entier", 'QUANTITE_ENTIERE');
            }
            $totalAvant = bcadd($totalAvant, bcmul((string) $l['prixUnitaire'], (string) $l['quantite'], 2), 2);
        }

        $remise = (string) ($data['remiseMontant'] ?? '0');
        $total = bcsub($totalAvant, $remise, 2);
        if (bccomp($total, '0', 2) < 0) {
            throw new ValidationException('La remise dépasse le total', 'REMISE_INVALIDE');
        }

        $devis = DB::transaction(function () use ($data, $boutiqueId, $request, $variantes, $totalAvant, $remise, $total) {
            $devis = Devis::create([
                'boutique_id'        => $boutiqueId,
                'reference'          => 'DEV-' . strtoupper(Str::random(8)),
                'client_nom'         => trim($data['clientNom']),
                'client_telephone'   => $data['clientTelephone'] ?? null,
                'statut'             => 'EN_COURS',
                'valable_jusqu_au'   => now()->addDays($data['validiteJours'] ?? 15)->toDateString(),
                'total_avant_remise' => $totalAvant,
                'remise_montant'     => $remise,
                'total_montant'      => $total,
                'notes'              => $data['notes'] ?? null,
                'user_id'            => $request->user()->id,
            ]);

            foreach ($data['lignes'] as $l) {
                $variante = $variantes->get($l['varianteId']);
                $devis->lignes()->create([
                    'variante_id'   => $variante->id,
                    'designation'   => $variante->produit->nom,
                    'quantite'      => $l['quantite'],
                    'prix_unitaire' => $l['prixUnitaire'],
                ]);
            }

            return $devis;
        });

        return $this->success($devis->load(self::RELATIONS), 201);
    }

    /** Accepter ou annuler un devis en cours (un devis converti en vente ne bouge plus). */
    public function changerStatut(Request $request, string $id): JsonResponse
    {
        $devis = $this->findOwned($request, $id);
        $data = $request->validate(['statut' => 'required|in:EN_COURS,ACCEPTE,ANNULE']);

        if ($devis->statut === 'CONVERTI') {
            throw new ConflictException('Ce devis est déjà devenu une vente', 'DEVIS_DEJA_CONVERTI');
        }

        $devis->update(['statut' => $data['statut']]);
        return $this->success($devis->fresh()->load(self::RELATIONS));
    }

    /** Transforme le devis en vente : le stock sort, au prix promis au client. Caisse ouverte requise. */
    public function convertir(Request $request, string $id): JsonResponse
    {
        $devis = $this->findOwned($request, $id)->load('lignes');

        if ($devis->statut === 'CONVERTI') {
            throw new ConflictException('Ce devis est déjà devenu une vente', 'DEVIS_DEJA_CONVERTI');
        }
        if ($devis->statut === 'ANNULE') {
            throw new ConflictException('Un devis annulé ne peut pas devenir une vente', 'DEVIS_ANNULE');
        }

        $credit = $request->validate([
            'clientId'       => 'sometimes|nullable|uuid',
            'echeanceJours'  => 'sometimes|nullable|integer|min:1|max:365',
            'acompteMontant' => 'sometimes|nullable|numeric|min:0',
            'acompteMode'    => 'sometimes|nullable|in:' . implode(',', \App\Services\CreditService::MODES_PAIEMENT),
        ]);

        $sortie = DB::transaction(function () use ($devis, $request, $credit) {
            $lignes = $devis->lignes->map(fn($l) => [
                'varianteId'   => $l->variante_id,
                'quantite'     => (string) $l->quantite,
                'prixUnitaire' => (string) $l->prix_unitaire,
            ])->all();

            $sortie = $this->sorties->creer($devis->boutique_id, $request->user()->id, 'VENTE', $lignes, [
                'remiseMontant' => (string) $devis->remise_montant,
                'notes'         => "Devis {$devis->reference} — {$devis->client_nom}",
                // Le client pro peut emporter la commande à crédit.
                'credit'        => empty($credit['clientId']) ? null : $credit,
            ]);

            $devis->update(['statut' => 'CONVERTI', 'sortie_id' => $sortie->id]);
            return $sortie;
        });

        return $this->success([
            'devis'  => $devis->fresh()->load(self::RELATIONS),
            'sortie' => $sortie->load(['lignes.variante.produit', 'user', 'boutique', 'transaction']),
        ]);
    }
}
