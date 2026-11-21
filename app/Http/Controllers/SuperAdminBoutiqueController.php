<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\SuppressionBoutique;
use App\Http\Traits\ApiResponse;
use App\Models\Abonnement;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Espace plateforme du Super Admin : gestion des boutiques-locataires
 * (inscription, activation, suspension) — distinct de BoutiqueController
 * qui expose désormais uniquement la boutique courante à ses propres
 * ADMIN/CAISSIER (voir routes "boutiques/me").
 */
class SuperAdminBoutiqueController extends Controller
{
    use ApiResponse;

    private function uniqueSlug(string $nom): string
    {
        $base = Str::slug($nom) ?: 'boutique';
        $slug = $base;
        $i = 2;
        while (Boutique::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }

    public function index(Request $request): JsonResponse
    {
        // Statuts remis à jour avant le filtre : une boutique dont l'essai vient de finir apparaît
        // « Suspendue » même si personne ne s'y est connecté depuis.
        Boutique::whereIn('statut', ['ESSAI', 'ACTIF', 'SUSPENDU'])->get()
            ->each(fn (Boutique $b) => $b->synchroniserStatutAbonnement());

        $q = Boutique::withCount(['users', 'produits'])->orderBy('created_at', 'desc');
        if ($request->filled('statut')) $q->where('statut', $request->statut);
        if ($request->filled('search')) $q->where('nom', 'like', '%' . $request->search . '%');
        if ($request->filled('typeCommerce')) $q->where('type_commerce', $request->typeCommerce);

        $boutiques = $q->get()->map(function (Boutique $b) {
            $abonnement = $b->abonnementActif();
            $arr = $b->toArray();
            $arr['abonnementActif'] = $abonnement;
            return $arr;
        });

        return $this->success($boutiques);
    }

    public function show(string $id): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');
        $b->synchroniserStatutAbonnement();

        $b = Boutique::withCount(['users', 'produits'])->find($id);
        $data = $b->toArray();
        $data['abonnementActif'] = $b->abonnementActif();
        $data['abonnements'] = $b->abonnements()->orderByDesc('date_fin')->get();
        $data['admins'] = $b->users()->where('role', 'ADMIN')->get(['id', 'email', 'created_at']);
        $data['inventaire'] = app(SuppressionBoutique::class)->inventaire($b);

        return $this->success($data);
    }

    /**
     * Inscrit une nouvelle boutique-locataire ET son premier compte ADMIN,
     * avec un abonnement d'essai. C'est l'action "j'inscris une boutique"
     * du Super Admin (le flux d'auto-inscription publique existe aussi,
     * voir AuthController::registerBoutique, et atterrit ici pour revue).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom'            => 'required|string|max:150',
            'typeCommerce'   => 'sometimes|in:' . implode(',', Boutique::TYPES_COMMERCE),
            'adresse'        => 'sometimes|nullable|string',
            'ville'          => 'sometimes|nullable|string',
            'whatsapp'       => 'sometimes|nullable|string',
            'email'          => 'sometimes|nullable|email',
            'telephone'      => 'sometimes|nullable|string',
            'adminEmail'     => 'required|email|unique:users,email',
            'adminPassword'  => 'required|string|min:8',
            'plan'           => 'sometimes|in:ESSAI,MENSUEL,TRIMESTRIEL,ANNUEL',
            'dureeJours'     => 'sometimes|integer|min:1',
        ]);

        $plan = $data['plan'] ?? 'ESSAI';
        $dureeJours = $data['dureeJours'] ?? Abonnement::DUREE_PLAN_JOURS[$plan];

        $result = DB::transaction(function () use ($request, $data, $plan, $dureeJours) {
            $boutique = Boutique::create([
                'nom'       => $data['nom'],
                'slug'      => $this->uniqueSlug($data['nom']),
                'type_commerce' => $data['typeCommerce'] ?? 'VETEMENTS',
                'adresse'   => $data['adresse'] ?? null,
                'ville'     => $data['ville'] ?? null,
                'whatsapp'  => $data['whatsapp'] ?? null,
                'email'     => $data['email'] ?? null,
                'telephone' => $data['telephone'] ?? null,
                'is_active' => true,
                'statut'    => $plan === 'ESSAI' ? 'ESSAI' : 'ACTIF',
            ]);

            $admin = User::create([
                'email'         => $data['adminEmail'],
                'password_hash' => Hash::make($data['adminPassword']),
                'role'          => 'ADMIN',
                'boutique_id'   => $boutique->id,
            ]);

            app(\App\Services\CategoriesDeDepart::class)->creer($boutique);

            $abonnement = Abonnement::create([
                'boutique_id' => $boutique->id,
                'plan'        => $plan,
                'statut'      => 'ACTIF',
                'date_debut'  => now(),
                'date_fin'    => now()->addDays($dureeJours),
                'montant'     => Abonnement::TARIFS_FCFA[$plan],
                'cree_par_id' => $request->user()->id,
            ]);

            return [$boutique, $admin, $abonnement];
        });

        [$boutique, $admin, $abonnement] = $result;

        AuditLog::record($request->user()->id, 'BOUTIQUE_REGISTER', 'Boutique', $boutique->id, "Inscription boutique {$boutique->nom} avec admin {$admin->email}");

        return $this->success([
            'boutique'   => $boutique,
            'admin'      => $admin,
            'abonnement' => $abonnement,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $data = $request->validate([
            'nom'       => 'sometimes|string|max:150',
            'adresse'   => 'sometimes|nullable|string',
            'ville'     => 'sometimes|nullable|string',
            'whatsapp'  => 'sometimes|nullable|string',
            'email'     => 'sometimes|nullable|email',
            'telephone' => 'sometimes|nullable|string',
            'logoUrl'   => 'sometimes|nullable|string',
            // Réservé au Super Admin : l'admin d'une boutique ne peut pas changer son type (BoutiqueController::updateMe).
            'typeCommerce' => 'sometimes|in:' . implode(',', Boutique::TYPES_COMMERCE),
        ]);
        if (array_key_exists('logoUrl', $data)) {
            $data['logo_url'] = $data['logoUrl'];
            unset($data['logoUrl']);
        }
        $ancienType = $b->type_commerce;
        if (array_key_exists('typeCommerce', $data)) {
            $data['type_commerce'] = $data['typeCommerce'];
            unset($data['typeCommerce']);
        }

        $b->update($data);

        if (isset($data['type_commerce']) && $data['type_commerce'] !== $ancienType) {
            AuditLog::record($request->user()->id, 'BOUTIQUE_TYPE_COMMERCE', 'Boutique', $b->id, "Type de commerce de {$b->nom} : {$ancienType} → {$data['type_commerce']}");
        }
        return $this->success($b->fresh());
    }

    /**
     * Change le statut d'accès de la boutique (activer / suspendre / archiver).
     */
    public function changerStatut(Request $request, string $id): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $data = $request->validate([
            'statut' => 'required|in:' . implode(',', Boutique::STATUTS),
            'motif'  => 'sometimes|nullable|string|max:255',
        ]);
        $statut = $data['statut'];
        $motif = isset($data['motif']) && trim($data['motif']) !== '' ? trim($data['motif']) : null;

        if (in_array($statut, Boutique::STATUTS_ACTIFS, true)) {
            // Réactiver sans abonnement en cours ne tiendrait pas : la boutique serait re-suspendue
            // à sa première requête. On le dit au Super Admin au lieu de faire semblant.
            $enCours = $b->abonnementEnCours();
            if (!$enCours) {
                $dernier = $b->abonnementActif();
                throw new ConflictException(
                    "Impossible de passer « {$b->nom} » en " . ($statut === 'ESSAI' ? 'essai' : 'actif')
                    . ' : aucun abonnement en cours'
                    . ($dernier ? ' (le dernier a pris fin le ' . $dernier->date_fin->format('d/m/Y') . ')' : '')
                    . '. Enregistrez ou prolongez d\'abord un abonnement.',
                    'ABONNEMENT_REQUIS',
                    ['dateFin' => $dernier?->date_fin?->toISOString()]
                );
            }
        }

        $b->update([
            'statut'           => $statut,
            'is_active'        => in_array($statut, Boutique::STATUTS_ACTIFS, true),
            'suspension_cause' => $statut === 'SUSPENDU' ? Boutique::SUSPENSION_MANUELLE : null,
            'suspension_motif' => in_array($statut, ['SUSPENDU', 'ARCHIVE'], true) ? $motif : null,
        ]);

        AuditLog::record(
            $request->user()->id,
            'BOUTIQUE_STATUT_CHANGE',
            'Boutique',
            $b->id,
            "Statut de {$b->nom} changé en {$data['statut']}" . (!empty($data['motif']) ? " ({$data['motif']})" : '')
        );

        return $this->success($b->fresh());
    }

    /**
     * Suppression DÉFINITIVE de la boutique et de toutes ses données (produits, stock, entrées,
     * ventes, caisse, clients, abonnements, comptes admin et caissiers…), en une transaction.
     * Pour garder l'historique, le Super Admin archive plutôt la boutique (statut ARCHIVE).
     *
     * Le nom exact de la boutique doit être renvoyé dans « confirmation » : un ancien écran ou un
     * appel isolé ne peut pas tout effacer par erreur.
     */
    public function destroy(Request $request, string $id, SuppressionBoutique $suppression): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $confirmation = trim((string) $request->input('confirmation', ''));
        if (mb_strtolower($confirmation) !== mb_strtolower(trim($b->nom))) {
            throw new ValidationException(
                "Pour supprimer définitivement « {$b->nom} », recopiez exactement son nom.",
                'CONFIRMATION_SUPPRESSION_INVALIDE',
                ['confirmation' => ["Le nom saisi ne correspond pas à « {$b->nom} »."]]
            );
        }

        $nom = $b->nom;
        $resultat = $suppression->supprimer($b);

        AuditLog::record(
            $request->user()->id,
            'BOUTIQUE_DESTROY',
            'Boutique',
            $id,
            "Suppression définitive de la boutique {$nom} : " . json_encode($resultat['supprime'], JSON_UNESCAPED_UNICODE)
        );

        return $this->success([
            'id'       => $id,
            'nom'      => $nom,
            'supprime' => $resultat['supprime'],
        ]);
    }
}
