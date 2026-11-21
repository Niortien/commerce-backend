<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Traits\ApiResponse;
use App\Models\Abonnement;
use App\Models\AuditLog;
use App\Models\Boutique;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Gestion manuelle des abonnements des boutiques par le Super Admin
 * (aucun paiement en ligne pour l'instant — l'encaissement se fait hors
 * plateforme et le Super Admin renouvelle/ajuste ici).
 *
 * Chaque écriture remet aussitôt le statut d'accès de la boutique en cohérence
 * (Boutique::synchroniserStatutAbonnement) et renvoie la boutique à jour : ce que le
 * Super Admin voit après l'action est ce que la boutique vit réellement.
 */
class SuperAdminAbonnementController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $q = Abonnement::with('boutique')->orderByDesc('date_fin');
        if ($request->filled('boutiqueId')) $q->where('boutique_id', $request->boutiqueId);
        if ($request->filled('statut'))     $q->where('statut', $request->statut);

        return $this->success($q->get());
    }

    /**
     * Enregistre un nouvel abonnement (renouvellement ou changement de plan).
     * L'abonnement précédemment ACTIF est marqué EXPIRE pour ne garder qu'un
     * seul abonnement courant par boutique.
     *
     * Accès de la boutique ensuite :
     * - en attente, en essai, active ou suspendue pour expiration → ouvert selon le plan ;
     * - suspendue manuellement ou archivée → inchangé, sauf si « reactiver » est envoyé à true.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'boutiqueId' => 'required|uuid|exists:boutiques,id',
            'plan'       => ['required', Rule::in(Abonnement::PLANS)],
            'dateDebut'  => 'sometimes|nullable|date',
            'dateFin'    => 'required|date',
            'montant'    => 'sometimes|nullable|numeric|min:0',
            'devise'     => 'sometimes|string|max:10',
            'notes'      => 'sometimes|nullable|string',
            'reactiver'  => 'sometimes|boolean',
        ]);

        $boutique = Boutique::findOrFail($data['boutiqueId']);
        $debut = !empty($data['dateDebut']) ? Carbon::parse($data['dateDebut']) : now();
        $fin = Abonnement::dateFinSaisie($data['dateFin']);
        $this->verifierPeriode($debut, $fin);

        $abonnement = DB::transaction(function () use ($boutique, $data, $debut, $fin, $request) {
            $boutique->abonnements()->where('statut', 'ACTIF')->update(['statut' => 'EXPIRE']);

            $abonnement = Abonnement::create([
                'boutique_id' => $boutique->id,
                'plan'        => $data['plan'],
                'statut'      => 'ACTIF',
                'date_debut'  => $debut,
                'date_fin'    => $fin,
                'montant'     => $data['montant'] ?? Abonnement::TARIFS_FCFA[$data['plan']],
                'devise'      => $data['devise'] ?? 'XOF',
                'notes'       => $data['notes'] ?? null,
                'cree_par_id' => $request->user()->id,
            ]);

            if (!$boutique->fermeeParLeSuperAdmin() || ($data['reactiver'] ?? false)) {
                $boutique->activerPourPlan($data['plan']);
            }

            return $abonnement;
        });

        AuditLog::record(
            $request->user()->id,
            'ABONNEMENT_CREATE',
            'Boutique',
            $boutique->id,
            "Abonnement {$data['plan']} enregistré pour {$boutique->nom} jusqu'au {$abonnement->date_fin->format('d/m/Y')}"
        );

        return $this->success($this->avecBoutique($abonnement), 201);
    }

    /**
     * Prolonger (dateFin), corriger le plan, le montant ou les notes, ou annuler (statut ANNULE :
     * « supprimer » l'abonnement en gardant la trace ; il ne compte plus dans les revenus).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $abonnement = Abonnement::find($id);
        if (!$abonnement) throw new NotFoundException('Abonnement introuvable', 'ABONNEMENT_NOT_FOUND');

        $data = $request->validate([
            'statut'    => ['sometimes', Rule::in(Abonnement::STATUTS)],
            'plan'      => ['sometimes', Rule::in(Abonnement::PLANS)],
            'dateFin'   => 'sometimes|date',
            'montant'   => 'sometimes|nullable|numeric|min:0',
            'notes'     => 'sometimes|nullable|string',
        ]);

        $update = [];
        if (isset($data['statut'])) $update['statut'] = $data['statut'];
        if (isset($data['plan']))   $update['plan'] = $data['plan'];
        if (isset($data['dateFin'])) {
            $update['date_fin'] = Abonnement::dateFinSaisie($data['dateFin']);
            $this->verifierPeriode($abonnement->date_debut, $update['date_fin']);
        }
        if (array_key_exists('montant', $data)) $update['montant'] = $data['montant'];
        if (array_key_exists('notes', $data))   $update['notes'] = $data['notes'];

        $boutique = $abonnement->boutique;

        DB::transaction(function () use ($abonnement, $update, $boutique) {
            // Un seul abonnement courant par boutique.
            if (($update['statut'] ?? null) === 'ACTIF') {
                $boutique->abonnements()->where('statut', 'ACTIF')->whereKeyNot($abonnement->id)->update(['statut' => 'EXPIRE']);
            }

            $abonnement->update($update);

            $boutique->refresh();
            $boutique->synchroniserStatutAbonnement();

            // Plan changé sur l'abonnement en cours d'une boutique ouverte : essai ↔ actif suit le plan.
            $enCours = $boutique->abonnementEnCours();
            if ($enCours && $enCours->is($abonnement) && in_array($boutique->statut, Boutique::STATUTS_ACTIFS, true)
                && $boutique->statut !== Boutique::statutPourPlan($enCours->plan)) {
                $boutique->activerPourPlan($enCours->plan);
            }
        });

        $changements = collect($update)->map(fn ($v, $k) => $k . '=' . ($v instanceof Carbon ? $v->format('d/m/Y') : (string) $v))->implode(', ');
        AuditLog::record($request->user()->id, 'ABONNEMENT_UPDATE', 'Abonnement', $abonnement->id, "Modification abonnement de {$boutique->nom} : {$changements}");

        return $this->success($this->avecBoutique($abonnement->fresh()));
    }

    private function verifierPeriode(Carbon $debut, Carbon $fin): void
    {
        if ($fin->lessThanOrEqualTo($debut)) {
            throw new ValidationException(
                'La date de fin doit être après la date de début (' . $debut->format('d/m/Y') . ').',
                'ABONNEMENT_PERIODE_INVALIDE',
                ['dateFin' => ['La date de fin doit être après le ' . $debut->format('d/m/Y') . '.']]
            );
        }
    }

    /** L'abonnement et la boutique telle qu'elle est maintenant (statut d'accès recalculé). */
    private function avecBoutique(Abonnement $abonnement): Abonnement
    {
        $boutique = Boutique::find($abonnement->boutique_id);
        $boutique?->synchroniserStatutAbonnement();
        return $abonnement->setRelation('boutique', $boutique?->fresh());
    }
}
