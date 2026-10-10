<?php

namespace App\Http\Controllers;

use App\Exceptions\ValidationException;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use App\Models\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Friperie : démarque des pièces qui traînent en rayon.
 *
 * Une pièce est « à démarquer » quand elle est en rayon depuis N jours sans avoir été démarquée entre-temps
 * (on compte depuis sa mise en rayon, ou depuis sa dernière démarque). Démarquer baisse son prix de vente
 * pour de bon ; le prix d'origine est gardé dans prix_initial.
 */
class DemarqueController extends Controller
{
    use ApiResponse;

    /** Les prix se paient en pièces de 50 F au plus petit. */
    private const ARRONDI = 50;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'joursMin' => 'sometimes|integer|min:0|max:3650',
            'balleId'  => 'sometimes|nullable|uuid',
            'page'     => 'sometimes|integer|min:1',
            'limit'    => 'sometimes|integer|min:1|max:200',
        ]);

        $jours = (int) ($data['joursMin'] ?? 30);
        $limite = now()->subDays($jours);

        $q = Produit::with(['categorie', 'variantes', 'balle:id,numero,libelle'])
            ->where('boutique_id', $this->tenantBoutiqueId($request))
            // Pièces uniques et tas des balles (friperie).
            ->where(fn($w) => $w->where('piece_unique', true)->orWhereNotNull('balle_id'))
            ->where('is_actif', true)
            ->whereHas('variantes', fn($v) => $v->where('quantite_stock', '>', 0))
            ->whereRaw('COALESCE(derniere_demarque_at, created_at) <= ?', [$limite])
            ->orderByRaw('COALESCE(derniere_demarque_at, created_at) asc');

        if (!empty($data['balleId'])) $q->where('balle_id', $data['balleId']);

        $page  = (int) ($data['page'] ?? 1);
        $limit = (int) ($data['limit'] ?? 100);
        $total = $q->count();
        $pieces = $q->skip(($page - 1) * $limit)->take($limit)->get()->map(fn(Produit $p) => array_merge($p->toArray(), [
            'jours_en_rayon' => (int) $p->created_at->diffInDays(now()),
            'jours_sans_demarque' => (int) ($p->derniere_demarque_at ?? $p->created_at)->diffInDays(now()),
        ]));

        return $this->paginated($pieces, $total, $page, $limit);
    }

    /**
     * Baisse le prix des pièces choisies : d'un pourcentage, ou à un prix fixe. Le nouveau prix est arrondi
     * aux 50 F et doit rester sous le prix actuel ; une pièce qui ne baisserait pas est laissée telle quelle.
     */
    public function appliquer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'produitIds'   => 'required|array|min:1|max:500',
            'produitIds.*' => 'uuid',
            'mode'         => 'required|in:POURCENTAGE,PRIX',
            'valeur'       => 'required|numeric|min:1',
        ]);

        if ($data['mode'] === 'POURCENTAGE' && $data['valeur'] >= 100) {
            throw new ValidationException('Une démarque se fait entre 1 et 99 %', 'DEMARQUE_INVALIDE');
        }

        $boutiqueId = $this->tenantBoutiqueId($request);
        $pieces = Produit::with('variantes')
            ->where('boutique_id', $boutiqueId)
            ->whereIn('id', array_unique($data['produitIds']))
            ->get();

        $avant = '0.00';
        $apres = '0.00';
        $demarquees = [];
        $ignorees = 0;

        DB::transaction(function () use ($pieces, $data, &$avant, &$apres, &$demarquees, &$ignorees) {
            foreach ($pieces as $piece) {
                $enRayon = $piece->variantes->contains(fn($v) => (float) $v->quantite_stock > 0);
                $actuel = (float) $piece->prix_vente;
                $nouveau = $this->nouveauPrix($actuel, $data['mode'], (float) $data['valeur']);

                if (!$enRayon || $nouveau >= $actuel) {
                    $ignorees++;
                    continue;
                }

                $piece->update([
                    'prix_initial'         => $piece->prix_initial ?? $piece->prix_vente,
                    'prix_vente'           => $nouveau,
                    'derniere_demarque_at' => now(),
                    'nb_demarques'         => $piece->nb_demarques + 1,
                ]);
                $avant = bcadd($avant, number_format($actuel, 2, '.', ''), 2);
                $apres = bcadd($apres, number_format($nouveau, 2, '.', ''), 2);
                $demarquees[] = $piece->id;
            }
        });

        if ($demarquees !== []) {
            $libelle = $data['mode'] === 'POURCENTAGE' ? "-{$data['valeur']} %" : "prix {$data['valeur']} F";
            AuditLog::record($request->user()->id, 'DEMARQUE', 'Produit', $demarquees[0], count($demarquees) . " pièce(s) démarquée(s) ({$libelle})");
        }

        return $this->success([
            'nb_demarquees' => count($demarquees),
            'nb_ignorees'   => $ignorees,
            'total_avant'   => $avant,
            'total_apres'   => $apres,
            'produit_ids'   => $demarquees,
        ]);
    }

    /** Prix après démarque, arrondi aux 50 F les plus proches, jamais sous 50 F. */
    private function nouveauPrix(float $actuel, string $mode, float $valeur): float
    {
        $brut = $mode === 'POURCENTAGE' ? $actuel * (1 - $valeur / 100) : $valeur;
        return max(self::ARRONDI, round($brut / self::ARRONDI) * self::ARRONDI);
    }
}
