<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Abonnement;
use App\Models\AuditLog;
use App\Models\Boutique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestion manuelle des abonnements des boutiques par le Super Admin
 * (aucun paiement en ligne pour l'instant — l'encaissement se fait hors
 * plateforme et le Super Admin renouvelle/ajuste ici).
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
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'boutiqueId' => 'required|uuid|exists:boutiques,id',
            'plan'       => 'required|in:ESSAI,MENSUEL,TRIMESTRIEL,ANNUEL',
            'dateDebut'  => 'sometimes|nullable|date',
            'dateFin'    => 'required|date|after:dateDebut',
            'montant'    => 'sometimes|nullable|numeric|min:0',
            'devise'     => 'sometimes|string|max:10',
            'notes'      => 'sometimes|nullable|string',
        ]);

        $boutique = Boutique::findOrFail($data['boutiqueId']);

        $boutique->abonnements()->where('statut', 'ACTIF')->update(['statut' => 'EXPIRE']);

        $abonnement = Abonnement::create([
            'boutique_id' => $boutique->id,
            'plan'        => $data['plan'],
            'statut'      => 'ACTIF',
            'date_debut'  => $data['dateDebut'] ?? now(),
            'date_fin'    => $data['dateFin'],
            'montant'     => $data['montant'] ?? null,
            'devise'      => $data['devise'] ?? 'XOF',
            'notes'       => $data['notes'] ?? null,
            'cree_par_id' => $request->user()->id,
        ]);

        // Renouveler l'abonnement réactive automatiquement l'accès si la
        // boutique n'a pas été suspendue/archivée manuellement.
        if (!in_array($boutique->statut, ['SUSPENDU', 'ARCHIVE'], true)) {
            $boutique->update(['statut' => $data['plan'] === 'ESSAI' ? 'ESSAI' : 'ACTIF', 'is_active' => true]);
        }

        AuditLog::record(
            $request->user()->id,
            'ABONNEMENT_CREATE',
            'Boutique',
            $boutique->id,
            "Abonnement {$data['plan']} enregistré pour {$boutique->nom} jusqu'au {$abonnement->date_fin->toDateString()}"
        );

        return $this->success($abonnement->load('boutique'), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $abonnement = Abonnement::find($id);
        if (!$abonnement) throw new NotFoundException('Abonnement introuvable', 'ABONNEMENT_NOT_FOUND');

        $data = $request->validate([
            'statut'    => 'sometimes|in:ACTIF,EXPIRE,SUSPENDU,ANNULE',
            'dateFin'   => 'sometimes|date',
            'montant'   => 'sometimes|nullable|numeric|min:0',
            'notes'     => 'sometimes|nullable|string',
        ]);

        $update = [];
        if (isset($data['statut']))  $update['statut'] = $data['statut'];
        if (isset($data['dateFin'])) $update['date_fin'] = $data['dateFin'];
        if (array_key_exists('montant', $data)) $update['montant'] = $data['montant'];
        if (array_key_exists('notes', $data))   $update['notes'] = $data['notes'];

        $abonnement->update($update);

        AuditLog::record($request->user()->id, 'ABONNEMENT_UPDATE', 'Abonnement', $abonnement->id, "Modification abonnement de {$abonnement->boutique->nom}");

        return $this->success($abonnement->fresh()->load('boutique'));
    }
}
