<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Vue transverse du Super Admin sur tous les acteurs de la plateforme :
 * admins et caissiers de toutes les boutiques, plus les autres super admins.
 * Contrairement à UserController (réservé à un ADMIN de boutique, scopé à
 * sa propre boutique), cette vue n'est pas filtrée par tenant.
 */
class SuperAdminUserController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $q = User::with('boutique')->orderBy('created_at', 'desc');
        if ($request->filled('boutiqueId')) $q->where('boutique_id', $request->boutiqueId);
        if ($request->filled('role'))       $q->where('role', $request->role);

        return $this->success($q->get());
    }

    public function show(string $id): JsonResponse
    {
        $user = User::with('boutique')->find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');
        return $this->success($user);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'      => 'required|email',
            'password'   => 'required|string|min:8',
            'role'       => 'required|in:SUPER_ADMIN,ADMIN,CAISSIER',
            'boutiqueId' => 'required_unless:role,SUPER_ADMIN|nullable|uuid|exists:boutiques,id',
        ]);

        if (User::where('email', $data['email'])->exists()) {
            throw new ConflictException('Un utilisateur avec cet email existe déjà', 'USER_EMAIL_EXISTS');
        }

        $user = User::create([
            'email'         => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role'          => $data['role'],
            'boutique_id'   => $data['role'] === 'SUPER_ADMIN' ? null : $data['boutiqueId'],
        ]);

        AuditLog::record($request->user()->id, 'USER_CREATE', 'User', $user->id, "Création utilisateur {$user->email} ({$user->role}) par le Super Admin");

        return $this->success($user, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');

        $data = $request->validate([
            'email'      => 'sometimes|email',
            'password'   => 'sometimes|string|min:8',
            'role'       => 'sometimes|in:SUPER_ADMIN,ADMIN,CAISSIER',
            'boutiqueId' => 'sometimes|nullable|uuid|exists:boutiques,id',
        ]);

        $update = [];
        if (isset($data['email']))      $update['email']         = $data['email'];
        if (isset($data['role']))       $update['role']          = $data['role'];
        if (array_key_exists('boutiqueId', $data)) $update['boutique_id'] = $data['boutiqueId'];
        if (isset($data['password']))   $update['password_hash'] = Hash::make($data['password']);

        $user->update($update);
        AuditLog::record($request->user()->id, 'USER_UPDATE', 'User', $user->id, "Modification utilisateur {$user->email} par le Super Admin");
        return $this->success($user->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');

        if ($user->id === $request->user()->id) {
            throw new ConflictException('Impossible de supprimer votre propre compte', 'CANNOT_DELETE_SELF');
        }

        $user->delete();
        AuditLog::record($request->user()->id, 'USER_DESTROY', 'User', $id, "Suppression utilisateur {$user->email} par le Super Admin");
        return $this->success($user);
    }
}
