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

class UserController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/users",
     *     tags={"Utilisateurs"},
     *     summary="Liste tous les utilisateurs (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Liste des utilisateurs",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/User")),
     *             @OA\Property(property="meta", type="object", nullable=true),
     *             @OA\Property(property="timestamp", type="string", format="date-time")
     *         )
     *     )
     * )
     */
    public function index(): JsonResponse
    {
        return $this->success(User::with('boutique')->orderBy('created_at')->get());
    }

    /**
     * @OA\Get(
     *     path="/users/{id}",
     *     tags={"Utilisateurs"},
     *     summary="Détail d'un utilisateur (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Utilisateur trouvé", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with('boutique')->find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');
        return $this->success($user);
    }

    /**
     * @OA\Post(
     *     path="/users",
     *     tags={"Utilisateurs"},
     *     summary="Créer un utilisateur (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string", minLength=8),
     *             @OA\Property(property="role", type="string", enum={"ADMIN","VENDEUR"}),
     *             @OA\Property(property="boutiqueId", type="string", format="uuid", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Utilisateur créé", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=409, description="Email déjà utilisé", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'      => 'required|email',
            'password'   => 'required|string|min:8',
            'role'       => 'sometimes|in:ADMIN,VENDEUR,GERANT',
            'boutiqueId' => 'sometimes|nullable|uuid|exists:boutiques,id',
        ]);

        if (User::where('email', $data['email'])->exists()) {
            throw new ConflictException('Un utilisateur avec cet email existe déjà', 'USER_EMAIL_EXISTS');
        }

        $user = User::create([
            'email'        => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'role'         => $data['role'] ?? 'VENDEUR',
            'boutique_id'  => $data['boutiqueId'] ?? null,
        ]);

        AuditLog::record($request->user()->id, 'USER_CREATE', 'User', $user->id, "Création utilisateur {$user->email} ({$user->role})");

        return $this->success($user, 201);
    }

    /**
     * @OA\Patch(
     *     path="/users/{id}",
     *     tags={"Utilisateurs"},
     *     summary="Modifier un utilisateur (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string", minLength=8),
     *             @OA\Property(property="role", type="string", enum={"ADMIN","VENDEUR"}),
     *             @OA\Property(property="boutiqueId", type="string", format="uuid", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Mis à jour", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');

        $data = $request->validate([
            'email'      => 'sometimes|email',
            'password'   => 'sometimes|string|min:8',
            'role'       => 'sometimes|in:ADMIN,VENDEUR,GERANT',
            'boutiqueId' => 'sometimes|nullable|uuid',
        ]);

        $update = [];
        if (isset($data['email']))      $update['email']         = $data['email'];
        if (isset($data['role']))       $update['role']          = $data['role'];
        if (isset($data['boutiqueId'])) $update['boutique_id']   = $data['boutiqueId'];
        if (isset($data['password']))   $update['password_hash'] = Hash::make($data['password']);

        $user->update($update);
        AuditLog::record($request->user()->id, 'USER_UPDATE', 'User', $user->id, "Modification utilisateur {$user->email}");
        return $this->success($user->fresh());
    }

    /**
     * @OA\Delete(
     *     path="/users/{id}",
     *     tags={"Utilisateurs"},
     *     summary="Supprimer un utilisateur (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Supprimé", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');
        $user->delete();
        AuditLog::record($request->user()->id, 'USER_DESTROY', 'User', $id, "Suppression utilisateur {$user->email}");
        return $this->success($user);
    }
}
