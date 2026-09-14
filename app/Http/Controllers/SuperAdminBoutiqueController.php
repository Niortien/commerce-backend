<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
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
        $q = Boutique::withCount(['users', 'produits'])->orderBy('created_at', 'desc');
        if ($request->filled('statut')) $q->where('statut', $request->statut);
        if ($request->filled('search')) $q->where('nom', 'like', '%' . $request->search . '%');

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
        $b = Boutique::withCount(['users', 'produits'])->find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $data = $b->toArray();
        $data['abonnements'] = $b->abonnements()->orderByDesc('date_fin')->get();
        $data['admins'] = $b->users()->where('role', 'ADMIN')->get(['id', 'email', 'created_at']);

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
        $dureeJours = $data['dureeJours'] ?? ($plan === 'ESSAI' ? 14 : 30);

        $result = DB::transaction(function () use ($request, $data, $plan, $dureeJours) {
            $boutique = Boutique::create([
                'nom'       => $data['nom'],
                'slug'      => $this->uniqueSlug($data['nom']),
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

            $abonnement = Abonnement::create([
                'boutique_id' => $boutique->id,
                'plan'        => $plan,
                'statut'      => 'ACTIF',
                'date_debut'  => now(),
                'date_fin'    => now()->addDays($dureeJours),
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
        ]);
        if (array_key_exists('logoUrl', $data)) {
            $data['logo_url'] = $data['logoUrl'];
            unset($data['logoUrl']);
        }

        $b->update($data);
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
            'statut' => 'required|in:EN_ATTENTE,ESSAI,ACTIF,SUSPENDU,ARCHIVE',
            'motif'  => 'sometimes|nullable|string',
        ]);

        $b->update([
            'statut'    => $data['statut'],
            'is_active' => in_array($data['statut'], Boutique::STATUTS_ACTIFS, true),
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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $aDesDonnees = $b->entrees()->exists() || $b->sorties()->exists() || $b->caisseSessions()->exists() || $b->produits()->exists();

        if ($aDesDonnees) {
            if ($b->statut === 'ARCHIVE') {
                throw new ConflictException('Boutique déjà archivée', 'BOUTIQUE_ALREADY_ARCHIVED');
            }
            $b->update(['statut' => 'ARCHIVE', 'is_active' => false]);
            AuditLog::record($request->user()->id, 'BOUTIQUE_ARCHIVE', 'Boutique', $b->id, "Archivage boutique {$b->nom} (données historiques conservées)");
            return $this->success($b->fresh());
        }

        $b->delete();
        AuditLog::record($request->user()->id, 'BOUTIQUE_DESTROY', 'Boutique', $id, "Suppression boutique {$b->nom}");
        return $this->success($b);
    }
}
