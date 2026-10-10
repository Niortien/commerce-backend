<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\Abonnement;
use App\Models\Boutique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Revenus de la plateforme par secteur (type de commerce) : ce que rapportent les abonnements,
 * mois par mois, et le revenu mensuel récurrent des abonnements en cours.
 *
 * Un abonnement compte au mois de son début (le paiement précède l'activation). Sans montant saisi,
 * on retient le tarif du plan ; l'essai ne rapporte rien et un abonnement annulé n'est pas compté.
 */
class SuperAdminRevenuController extends Controller
{
    use ApiResponse;

    /** Tarifs publics, en FCFA (voir le site et l'inscription). */
    public const TARIFS = ['ESSAI' => 0, 'MENSUEL' => 10000, 'TRIMESTRIEL' => 27000, 'ANNUEL' => 96000];
    private const DUREE_MOIS = ['ESSAI' => 1, 'MENSUEL' => 1, 'TRIMESTRIEL' => 3, 'ANNUEL' => 12];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['annee' => 'sometimes|integer|min:2020|max:2100']);
        $annee = (int) ($data['annee'] ?? now()->year);
        $moisCourant = now()->format('Y-m');

        $boutiques = Boutique::select(['id', 'type_commerce', 'statut'])->get()->keyBy('id');
        $abonnements = Abonnement::whereIn('boutique_id', $boutiques->keys())->where('statut', '!=', 'ANNULE')->get();

        $secteurs = [];
        foreach (Boutique::TYPES_COMMERCE as $type) {
            $secteurs[$type] = [
                'type_commerce' => $type, 'nb_boutiques' => 0, 'nb_actives' => 0, 'nb_essai' => 0,
                'nb_abonnements_payes' => 0, 'revenus_annee' => 0.0, 'revenus_mois_courant' => 0.0,
                'revenu_mensuel_recurrent' => 0.0,
            ];
        }
        $typeDe = fn(string $boutiqueId) => $this->secteur($boutiques->get($boutiqueId)?->type_commerce);

        foreach ($boutiques as $b) {
            $t = $this->secteur($b->type_commerce);
            $secteurs[$t]['nb_boutiques']++;
            if ($b->statut === 'ACTIF') $secteurs[$t]['nb_actives']++;
            if ($b->statut === 'ESSAI') $secteurs[$t]['nb_essai']++;
        }

        $parMois = [];
        for ($m = 1; $m <= 12; $m++) {
            $parMois[sprintf('%d-%02d', $annee, $m)] = ['mois' => sprintf('%d-%02d', $annee, $m), 'total' => 0.0, 'secteurs' => []];
        }

        foreach ($abonnements as $a) {
            $montant = $this->montant($a);
            if ($montant <= 0) continue;
            $t = $typeDe($a->boutique_id);
            $mois = $a->date_debut->format('Y-m');

            if ((int) $a->date_debut->format('Y') === $annee) {
                $secteurs[$t]['nb_abonnements_payes']++;
                $secteurs[$t]['revenus_annee'] += $montant;
                $parMois[$mois]['total'] += $montant;
                $parMois[$mois]['secteurs'][$t] = ($parMois[$mois]['secteurs'][$t] ?? 0) + $montant;
            }
            if ($mois === $moisCourant) $secteurs[$t]['revenus_mois_courant'] += $montant;
            if ($a->estEnCours()) {
                $secteurs[$t]['revenu_mensuel_recurrent'] += round($montant / self::DUREE_MOIS[$a->plan], 2);
            }
        }

        $lignes = array_values($secteurs);
        return $this->success([
            'annee'                    => $annee,
            'total_annee'              => array_sum(array_column($lignes, 'revenus_annee')),
            'total_mois_courant'       => array_sum(array_column($lignes, 'revenus_mois_courant')),
            'revenu_mensuel_recurrent' => array_sum(array_column($lignes, 'revenu_mensuel_recurrent')),
            'par_secteur'              => $lignes,
            // Liste plutôt que dictionnaire : les clés JSON sont converties en camelCase.
            'par_mois'                 => array_map(fn($m) => [
                'mois'        => $m['mois'],
                'total'       => $m['total'],
                'par_secteur' => array_map(
                    fn($type, $montant) => ['type_commerce' => $type, 'montant' => $montant],
                    array_keys($m['secteurs']),
                    array_values($m['secteurs'])
                ),
            ], array_values($parMois)),
        ]);
    }

    private function montant(Abonnement $a): float
    {
        return $a->montant !== null ? (float) $a->montant : (float) (self::TARIFS[$a->plan] ?? 0);
    }

    /** Une valeur inconnue (ancien « MODE »…) compte comme vêtements, l'option historique. */
    private function secteur(?string $type): string
    {
        return in_array($type, Boutique::TYPES_COMMERCE, true) ? $type : 'VETEMENTS';
    }
}
