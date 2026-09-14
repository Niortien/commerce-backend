<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Gestion des caissiers PAR l'ADMIN de la boutique — strictement scopée à
 * sa propre boutique. Un ADMIN ne voit et ne gère jamais les comptes d'une
 * autre boutique (voir SuperAdminUserController pour la vue transverse du
 * Super Admin) ni ne peut créer d'ADMIN ou de SUPER_ADMIN par ce biais.
 */
class UserController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        return $this->success(
            User::where('boutique_id', $boutiqueId)->orderBy('created_at')->get()
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $user = User::where('boutique_id', $boutiqueId)->find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');
        return $this->success($user);
    }

    /**
     * Crée un caissier pour la boutique courante. Le rôle est toujours
     * CAISSIER : seul le Super Admin peut créer un compte ADMIN.
     */
    public function store(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);

        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        if (User::where('email', $data['email'])->exists()) {
            throw new ConflictException('Un utilisateur avec cet email existe déjà', 'USER_EMAIL_EXISTS');
        }

        $user = User::create([
            'email'         => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role'          => 'CAISSIER',
            'boutique_id'   => $boutiqueId,
        ]);

        AuditLog::record($request->user()->id, 'USER_CREATE', 'User', $user->id, "Création caissier {$user->email}");

        return $this->success($user, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $user = User::where('boutique_id', $boutiqueId)->find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');

        if ($user->role !== 'CAISSIER') {
            throw new DomainException("Seuls les comptes caissiers peuvent être modifiés depuis cet écran.", 403, 'FORBIDDEN');
        }

        $data = $request->validate([
            'email'    => 'sometimes|email',
            'password' => 'sometimes|string|min:8',
        ]);

        $update = [];
        if (isset($data['email']))    $update['email']         = $data['email'];
        if (isset($data['password'])) $update['password_hash'] = Hash::make($data['password']);

        $user->update($update);
        AuditLog::record($request->user()->id, 'USER_UPDATE', 'User', $user->id, "Modification caissier {$user->email}");
        return $this->success($user->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $user = User::where('boutique_id', $boutiqueId)->find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');

        if ($user->role !== 'CAISSIER') {
            throw new DomainException("Seuls les comptes caissiers peuvent être supprimés depuis cet écran.", 403, 'FORBIDDEN');
        }

        $user->delete();
        AuditLog::record($request->user()->id, 'USER_DESTROY', 'User', $id, "Suppression caissier {$user->email}");
        return $this->success($user);
    }
}
