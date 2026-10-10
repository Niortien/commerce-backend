<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\CaisseSession;
use App\Models\Client;
use App\Models\OperationCredit;
use App\Models\Sortie;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comptes des clients à crédit.
 *
 * Le solde se recalcule toujours depuis les opérations : ventes à crédit non annulées − règlements.
 * Les règlements soldent d'abord les ventes les plus anciennes ; ce qui reste dû après l'échéance
 * d'une vente est « en retard ».
 */
class CreditService
{
    public const MODES_PAIEMENT = ['CASH', 'WAVE', 'ORANGE_MONEY', 'CARTE', 'MTN_MONEY'];

    public function clientDeLaBoutique(string $boutiqueId, string $clientId): Client
    {
        $client = Client::where('boutique_id', $boutiqueId)->find($clientId);
        if (!$client) throw new NotFoundException('Client introuvable', 'CLIENT_NOT_FOUND');
        return $client;
    }

    /**
     * Inscrit une vente au compte du client, avec un éventuel acompte encaissé tout de suite.
     * Appelé dans la transaction qui crée la sortie.
     *
     * @param array{clientId: string, echeanceJours?: int|null, acompteMontant?: int|float|string|null, acompteMode?: string|null} $credit
     */
    public function vendreACredit(Sortie $sortie, array $credit, string $userId): void
    {
        $client = $this->clientDeLaBoutique($sortie->boutique_id, $credit['clientId']);
        if (!$client->is_actif) {
            throw new ConflictException("« {$client->nom} » n'achète plus à crédit", 'CLIENT_INACTIF');
        }

        $total = number_format((float) $sortie->total_montant, 2, '.', '');
        $acompte = number_format((float) ($credit['acompteMontant'] ?? 0), 2, '.', '');
        if (bccomp($acompte, $total, 2) >= 0) {
            throw new ValidationException("L'acompte couvre toute la vente : encaissez-la comptant", 'ACOMPTE_TROP_ELEVE');
        }

        $reste = bcsub($total, $acompte, 2);
        if ($client->plafond_credit !== null) {
            $disponible = bcsub((string) $client->plafond_credit, $this->etat($client)['solde'], 2);
            if (bccomp($reste, $disponible, 2) > 0) {
                throw new ConflictException(
                    "Plafond de crédit dépassé : « {$client->nom} » peut encore prendre " . $this->lisible(max(0, (float) $disponible)) . ' F à crédit',
                    'PLAFOND_DEPASSE',
                    ['disponible' => (float) $disponible, 'demande' => (float) $reste]
                );
            }
        }

        $sortie->update(['client_id' => $client->id]);
        OperationCredit::create([
            'boutique_id' => $sortie->boutique_id,
            'client_id'   => $client->id,
            'type'        => 'VENTE',
            'montant'     => $total,
            'sortie_id'   => $sortie->id,
            'echeance'    => now()->addDays((int) ($credit['echeanceJours'] ?? 30))->toDateString(),
            'user_id'     => $userId,
        ]);

        if (bccomp($acompte, '0', 2) > 0) {
            $this->encaisser($client, $acompte, $credit['acompteMode'] ?? 'CASH', $userId, "Acompte vente {$sortie->reference}", $sortie->id);
        }
    }

    /** Le client paie (tout ou partie de ce qu'il doit) : l'argent entre dans la caisse ouverte. */
    public function regler(Client $client, int|float|string $montant, string $mode, string $userId, ?string $notes = null): OperationCredit
    {
        $montant = number_format((float) $montant, 2, '.', '');
        $solde = $this->etat($client)['solde'];
        if (bccomp($montant, $solde, 2) > 0) {
            throw new ValidationException(
                "« {$client->nom} » ne doit que " . $this->lisible((float) $solde) . ' F',
                'REGLEMENT_TROP_ELEVE',
                ['solde' => (float) $solde]
            );
        }

        return DB::transaction(fn() => $this->encaisser($client, $montant, $mode, $userId, $notes ?: 'Règlement', null));
    }

    /** Une vente à crédit annulée ne compte plus dans la dette. Les acomptes déjà versés restent acquis (avoir). */
    public function annulerVente(Sortie $sortie, string $userId): void
    {
        $vente = OperationCredit::where('sortie_id', $sortie->id)->where('type', 'VENTE')->first();
        if (!$vente || OperationCredit::where('sortie_id', $sortie->id)->where('type', 'ANNULATION')->exists()) return;

        OperationCredit::create([
            'boutique_id' => $vente->boutique_id,
            'client_id'   => $vente->client_id,
            'type'        => 'ANNULATION',
            'montant'     => $vente->montant,
            'sortie_id'   => $sortie->id,
            'notes'       => "Annulation vente {$sortie->reference}",
            'user_id'     => $userId,
        ]);
    }

    /**
     * État du compte : solde (négatif = avoir), part en retard, prochaine échéance, retard le plus ancien.
     *
     * @param Collection<int, OperationCredit>|null $operations déjà chargées (sinon lues en base)
     * @return array{solde: string, en_retard: string, prochaine_echeance: ?string, jours_retard: int, nb_ventes_ouvertes: int}
     */
    public function etat(Client $client, ?Collection $operations = null): array
    {
        $operations ??= OperationCredit::where('client_id', $client->id)->orderBy('created_at')->orderByRaw(OperationCredit::ORDRE_A_HEURE_EGALE)->get();

        $annulees = $operations->where('type', 'ANNULATION')->pluck('sortie_id')->filter()->all();
        $ventes = $operations->where('type', 'VENTE')
            ->reject(fn($o) => $o->sortie_id && in_array($o->sortie_id, $annulees, true))
            ->sortBy('created_at');
        $paye = (string) $operations->where('type', 'REGLEMENT')->reduce(fn($s, $o) => bcadd($s, (string) $o->montant, 2), '0.00');

        $du = (string) $ventes->reduce(fn($s, $o) => bcadd($s, (string) $o->montant, 2), '0.00');
        $aujourdhui = now()->toDateString();
        $restePaye = $paye;
        $enRetard = '0.00';
        $prochaine = null;
        $plusAncienRetard = null;
        $ouvertes = 0;

        // Les règlements soldent les ventes de la plus ancienne à la plus récente.
        foreach ($ventes as $vente) {
            $montant = (string) $vente->montant;
            $couvert = bccomp($restePaye, $montant, 2) >= 0 ? $montant : $restePaye;
            $restePaye = bcsub($restePaye, $couvert, 2);
            $reste = bcsub($montant, $couvert, 2);
            if (bccomp($reste, '0', 2) <= 0) continue;

            $ouvertes++;
            $echeance = $vente->echeance?->toDateString();
            if ($echeance && $echeance < $aujourdhui) {
                $enRetard = bcadd($enRetard, $reste, 2);
                $plusAncienRetard ??= $echeance;
            } elseif ($echeance && ($prochaine === null || $echeance < $prochaine)) {
                $prochaine = $echeance;
            }
        }

        return [
            'solde'              => bcsub($du, $paye, 2),
            'en_retard'          => $enRetard,
            'prochaine_echeance' => $prochaine,
            'jours_retard'       => $plusAncienRetard ? (int) now()->startOfDay()->diffInDays($plusAncienRetard) : 0,
            'nb_ventes_ouvertes' => $ouvertes,
        ];
    }

    /** Crée la transaction de caisse et le règlement correspondant. */
    private function encaisser(Client $client, string $montant, string $mode, string $userId, string $notes, ?string $sortieId): OperationCredit
    {
        if (!in_array($mode, self::MODES_PAIEMENT, true)) {
            throw new ValidationException('Mode de paiement inconnu', 'MODE_PAIEMENT_INVALIDE');
        }
        $session = CaisseSession::where('statut', 'OUVERTE')->where('boutique_id', $client->boutique_id)->orderBy('date_ouverture', 'desc')->first();
        if (!$session) {
            throw new ConflictException('Aucune session de caisse ouverte', 'NO_ACTIVE_SESSION');
        }

        $transaction = Transaction::create([
            'session_id'    => $session->id,
            'sortie_id'     => $sortieId,
            'montant'       => $montant,
            'mode_paiement' => $mode,
            'notes'         => "{$notes} — {$client->nom}",
        ]);

        return OperationCredit::create([
            'boutique_id'    => $client->boutique_id,
            'client_id'      => $client->id,
            'type'           => 'REGLEMENT',
            'montant'        => $montant,
            'sortie_id'      => $sortieId,
            'transaction_id' => $transaction->id,
            'mode_paiement'  => $mode,
            'notes'          => $notes,
            'user_id'        => $userId,
        ]);
    }

    /** 12500.00 → « 12 500 » */
    private function lisible(float $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }
}
