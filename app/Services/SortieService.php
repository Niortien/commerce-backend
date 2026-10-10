<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\CaisseSession;
use App\Models\MouvementStock;
use App\Models\Sortie;
use App\Models\Transaction;
use App\Models\Variante;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création et annulation des sorties de stock (ventes, pertes, dons, retours).
 * Partagé par la caisse (SortieController) et la conversion d'un devis en vente (DevisController).
 *
 * Restaurant : vendre un PLAT ne touche pas au stock du plat (préparé à la commande) mais retire de
 * chaque ingrédient la quantité de sa fiche technique × le nombre de portions.
 */
class SortieService
{
    public const MODES_SERVICE = ['SUR_PLACE', 'A_EMPORTER', 'LIVRAISON'];

    public function __construct(private StockMovementService $movements, private CreditService $credits) {}

    /**
     * @param array<int, array{varianteId: string, quantite: int|float|string, prixUnitaire: int|float|string}> $lignes
     * @param array{remiseMontant?: int|float|string|null, notes?: string|null, modeService?: string|null, tableLabel?: string|null, credit?: array{clientId: string, echeanceJours?: int|null, acompteMontant?: int|float|string|null, acompteMode?: string|null}|null} $options
     *        credit : vente à crédit inscrite au compte du client (VENTE seulement).
     *        session / clientRef / venduLe : vente faite hors connexion puis envoyée (voir enregistrerHorsLigne).
     */
    public function creer(string $boutiqueId, string $userId, string $type, array $lignes, array $options = []): Sortie
    {
        $variantes = Variante::with('produit.recette')
            ->where('boutique_id', $boutiqueId)
            ->whereIn('id', array_column($lignes, 'varianteId'))
            ->get()
            ->keyBy('id');

        foreach ($lignes as $l) {
            $variante = $variantes->get($l['varianteId']);
            if (!$variante || !$variante->produit) {
                throw new NotFoundException('Variante introuvable', 'VARIANTE_NOT_FOUND');
            }
            $produit = $variante->produit;
            if ($produit->seVendALUnite() && floor((float) $l['quantite']) != (float) $l['quantite']) {
                throw new ValidationException("« {$produit->nom} » se vend à l'unité : la quantité doit être un nombre entier", 'QUANTITE_ENTIERE');
            }
            if ($produit->piece_unique && (float) $l['quantite'] != 1.0) {
                throw new ValidationException("« {$produit->nom} » est une pièce unique : une seule à la fois", 'PIECE_UNIQUE_QUANTITE');
            }
            if ($type === 'VENTE' && $produit->nature === 'INGREDIENT') {
                throw new ValidationException("« {$produit->nom} » est un ingrédient : il ne se vend pas seul", 'INGREDIENT_NON_VENDABLE');
            }
        }

        if ($type === 'VENTE' && empty($options['session'])) {
            $session = CaisseSession::where('statut', 'OUVERTE')->where('boutique_id', $boutiqueId)->first();
            if (!$session) {
                throw new ConflictException('Aucune session de caisse ouverte', 'NO_ACTIVE_SESSION');
            }
        }

        $totalAvant = '0.00';
        foreach ($lignes as $l) {
            $totalAvant = bcadd($totalAvant, bcmul((string) $l['prixUnitaire'], (string) $l['quantite'], 2), 2);
        }
        $remise = (string) ($options['remiseMontant'] ?? '0');
        $totalMontant = bcsub($totalAvant, $remise, 2);
        if (bccomp($totalMontant, '0', 2) < 0) {
            throw new ValidationException('La remise dépasse le total', 'REMISE_INVALIDE');
        }

        $reference = 'SRT-' . strtoupper(Str::random(8));

        return DB::transaction(function () use ($boutiqueId, $userId, $type, $lignes, $options, $variantes, $reference, $totalAvant, $remise, $totalMontant) {
            $sortie = Sortie::create([
                'reference'          => $reference,
                'type'               => $type,
                'mode_service'       => $type === 'VENTE' ? ($options['modeService'] ?? null) : null,
                'table_label'        => $type === 'VENTE' ? ($options['tableLabel'] ?? null) : null,
                'total_avant_remise' => $totalAvant,
                'remise_montant'     => $remise,
                'total_montant'      => $totalMontant,
                'notes'              => $options['notes'] ?? null,
                'client_ref'         => $options['clientRef'] ?? null,
                'user_id'            => $userId,
                'boutique_id'        => $boutiqueId,
            ]);
            if (!empty($options['venduLe'])) {
                $sortie->forceFill(['created_at' => $options['venduLe'], 'updated_at' => $options['venduLe']])->save();
            }

            foreach ($lignes as $ligne) {
                $sortie->lignes()->create([
                    'variante_id'   => $ligne['varianteId'],
                    'quantite'      => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prixUnitaire'],
                ]);

                $produit = $variantes->get($ligne['varianteId'])->produit;
                if ($produit->nature === 'PLAT') {
                    // Le plat est préparé à la commande : on retire ses ingrédients, pas le plat lui-même.
                    foreach ($produit->recette as $r) {
                        $quantite = bcmul(number_format($r->quantite, 3, '.', ''), (string) $ligne['quantite'], 3);
                        $this->movements->create($r->ingredient_variante_id, 'SORTIE', $quantite, $userId, "Plat : {$produit->nom} × {$ligne['quantite']}", null, $reference);
                    }
                } else {
                    $this->movements->create($ligne['varianteId'], 'SORTIE', $ligne['quantite'], $userId, null, null, $reference);
                }
            }

            if ($type === 'VENTE' && !empty($options['credit']['clientId'])) {
                $this->credits->vendreACredit($sortie, $options['credit'], $userId);
            }

            return $sortie;
        });
    }

    /**
     * Vente faite sans internet, envoyée quand la connexion revient.
     *
     * L'appareil donne à chaque vente une référence (clientRef) : si elle arrive deux fois (réseau
     * coupé pendant l'envoi), la seconde renvoie la première au lieu d'en créer une autre.
     * La vente est rangée dans la session de caisse ouverte au moment où elle a eu lieu, sinon dans
     * celle ouverte maintenant. Le paiement est enregistré avec elle, d'un seul bloc.
     *
     * @param array<int, array{varianteId: string, quantite: int|float|string, prixUnitaire: int|float|string}> $lignes
     * @param array{clientRef: string, venduLe: string, modePaiement: string, montantPaye?: int|float|string|null, remiseMontant?: int|float|string|null, notes?: string|null, modeService?: string|null, tableLabel?: string|null} $infos
     * @return array{0: Sortie, 1: bool} la vente et « vient d'être créée »
     */
    public function enregistrerHorsLigne(string $boutiqueId, string $userId, array $lignes, array $infos): array
    {
        $existante = Sortie::with('transaction')->where('boutique_id', $boutiqueId)->where('client_ref', $infos['clientRef'])->first();
        if ($existante) {
            // La vente était arrivée mais pas son paiement (réponse perdue entre les deux) : on le complète.
            if (!$existante->transaction && $existante->type === 'VENTE') {
                $session = CaisseSession::where('boutique_id', $boutiqueId)->where('statut', 'OUVERTE')->orderByDesc('date_ouverture')->first();
                if ($session) {
                    Transaction::create([
                        'session_id'    => $session->id,
                        'sortie_id'     => $existante->id,
                        'montant'       => $infos['montantPaye'] ?? $existante->total_montant,
                        'mode_paiement' => $infos['modePaiement'],
                        'notes'         => 'Vente faite hors connexion',
                    ]);
                }
            }
            return [$existante, false];
        }

        $venduLe = Carbon::parse($infos['venduLe']);
        if ($venduLe->isFuture()) $venduLe = Carbon::now();

        $session = CaisseSession::where('boutique_id', $boutiqueId)
            ->where('date_ouverture', '<=', $venduLe)
            ->where(fn($q) => $q->whereNull('date_fermeture')->orWhere('date_fermeture', '>=', $venduLe))
            ->orderByDesc('date_ouverture')
            ->first()
            ?? CaisseSession::where('boutique_id', $boutiqueId)->where('statut', 'OUVERTE')->orderByDesc('date_ouverture')->first();
        if (!$session) {
            throw new ConflictException('Aucune session de caisse pour ranger cette vente : ouvre la caisse puis renvoie-la', 'NO_ACTIVE_SESSION');
        }

        try {
            $sortie = DB::transaction(function () use ($boutiqueId, $userId, $lignes, $infos, $venduLe, $session) {
                $sortie = $this->creer($boutiqueId, $userId, 'VENTE', $lignes, [
                    'remiseMontant' => $infos['remiseMontant'] ?? null,
                    'notes'         => $infos['notes'] ?? null,
                    'modeService'   => $infos['modeService'] ?? null,
                    'tableLabel'    => $infos['tableLabel'] ?? null,
                    'session'       => $session,
                    'clientRef'     => $infos['clientRef'],
                    'venduLe'       => $venduLe,
                ]);
                $transaction = Transaction::create([
                    'session_id'    => $session->id,
                    'sortie_id'     => $sortie->id,
                    'montant'       => $infos['montantPaye'] ?? $sortie->total_montant,
                    'mode_paiement' => $infos['modePaiement'],
                    'notes'         => 'Vente faite hors connexion',
                ]);
                $transaction->forceFill(['created_at' => $venduLe])->save();
                return $sortie;
            });
        } catch (QueryException $e) {
            // Deux envois simultanés de la même vente : l'index unique a refusé le second.
            $existante = Sortie::where('boutique_id', $boutiqueId)->where('client_ref', $infos['clientRef'])->first();
            if ($existante) return [$existante, false];
            throw $e;
        }

        return [$sortie, true];
    }

    /**
     * Remet en stock ce que la sortie a retiré. On rejoue ses mouvements (et non ses lignes) :
     * pour un plat, ce sont ses ingrédients qui reviennent en stock.
     */
    public function remettreEnStock(Sortie $sortie, string $userId): void
    {
        $mouvements = MouvementStock::where('reference_sortie', $sortie->reference)->where('type', 'SORTIE')->get();
        $motif = 'Annulation sortie ' . $sortie->reference;

        if ($mouvements->isEmpty()) {
            // Sorties anciennes sans mouvement référencé : on se fie aux lignes.
            foreach ($sortie->lignes as $ligne) {
                $this->movements->create($ligne->variante_id, 'RETOUR', $ligne->quantite, $userId, $motif);
            }
            return;
        }

        foreach ($mouvements as $m) {
            $this->movements->create($m->variante_id, 'RETOUR', $m->quantite, $userId, $motif);
        }
    }
}
