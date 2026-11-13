<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\WebVital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/**
 * Vitesse réelle du site (Web Vitals).
 *
 * - `store` (public) : le navigateur d'un visiteur envoie ses mesures en quittant la page
 *   (navigator.sendBeacon, corps JSON en text/plain pour éviter un aller-retour CORS).
 *   Rien de personnel n'est gardé : ni utilisateur, ni IP.
 * - `index` (Super Admin) : le 75e centile de chaque mesure, par page et par appareil.
 */
class WebVitalController extends Controller
{
    use ApiResponse;

    private const METRIQUES = ['LCP', 'INP', 'CLS', 'FCP', 'TTFB'];
    /** Au-delà, on lit les plus récentes seulement : la page reste rapide quel que soit le trafic. */
    private const LIGNES_MAX = 50000;
    private const RETENTION_JOURS = 90;

    public function store(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return response()->noContent(400);
        }

        $validator = Validator::make($payload, [
            'page'               => ['required', 'string', 'max:120', 'regex:/^\/[A-Za-z0-9\/\[\]_\-.]*$/'],
            'appareil'           => 'required|in:mobile,ordinateur',
            'connexion'          => 'nullable|string|max:10',
            'mesures'            => 'required|array|min:1|max:10',
            'mesures.*.metrique' => 'required|in:' . implode(',', self::METRIQUES),
            'mesures.*.valeur'   => 'required|numeric|min:0|max:120000',
            'mesures.*.note'     => 'required|in:good,needs-improvement,poor',
        ]);
        if ($validator->fails()) {
            return response()->noContent(422);
        }
        $data = $validator->validated();

        $maintenant = now();
        WebVital::insert(array_map(fn(array $m) => [
            'metrique'   => $m['metrique'],
            'valeur'     => (float) $m['valeur'],
            'note'       => $m['note'],
            'page'       => $data['page'],
            'appareil'   => $data['appareil'],
            'connexion'  => $data['connexion'] ?? null,
            'created_at' => $maintenant,
        ], $data['mesures']));

        // Ménage occasionnel, sans tâche planifiée : on ne garde que les 90 derniers jours.
        if (random_int(1, 200) === 1) {
            WebVital::where('created_at', '<', $maintenant->copy()->subDays(self::RETENTION_JOURS))->delete();
        }

        return response()->noContent();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['jours' => 'sometimes|integer|min:1|max:' . self::RETENTION_JOURS]);
        $jours = (int) ($data['jours'] ?? 28);

        $lignes = WebVital::where('created_at', '>=', now()->subDays($jours))
            ->orderByDesc('created_at')
            ->limit(self::LIGNES_MAX)
            ->get(['metrique', 'valeur', 'note', 'page', 'appareil']);

        $groupes = [];
        foreach ($lignes as $l) {
            $cle = $l->page . '|' . $l->appareil;
            $groupes[$cle] ??= ['page' => $l->page, 'appareil' => $l->appareil, 'mesures' => []];
            $groupes[$cle]['mesures'][$l->metrique][] = ['valeur' => $l->valeur, 'bonne' => $l->note === 'good'];
        }

        $parPage = [];
        foreach ($groupes as $g) {
            $metriques = [];
            foreach (self::METRIQUES as $m) {
                $valeurs = $g['mesures'][$m] ?? [];
                if (!$valeurs) continue;
                $metriques[] = [
                    'metrique'   => $m,
                    'p75'        => $this->centile(array_column($valeurs, 'valeur'), 0.75),
                    'nb'         => count($valeurs),
                    'part_bonne' => round(count(array_filter($valeurs, fn($v) => $v['bonne'])) / count($valeurs), 3),
                ];
            }
            $parPage[] = [
                'page'      => $g['page'],
                'appareil'  => $g['appareil'],
                // Une visite envoie toujours son TTFB : c'est le meilleur compte de visites mesurées.
                'nb_visites' => count($g['mesures']['TTFB'] ?? $g['mesures']['LCP'] ?? []),
                'metriques' => $metriques,
            ];
        }
        usort($parPage, fn($a, $b) => $b['nb_visites'] <=> $a['nb_visites']);

        return $this->success([
            'jours'         => $jours,
            'total_mesures' => $lignes->count(),
            'par_page'      => $parPage,
        ]);
    }

    /** Centile par interpolation linéaire (même définition que les outils de Google). */
    private function centile(array $valeurs, float $p): float
    {
        sort($valeurs);
        $n = count($valeurs);
        if ($n === 1) return round($valeurs[0], 3);
        $rang = $p * ($n - 1);
        $bas = (int) floor($rang);
        $haut = min($bas + 1, $n - 1);
        return round($valeurs[$bas] + ($valeurs[$haut] - $valeurs[$bas]) * ($rang - $bas), 3);
    }
}
