<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Http\Traits\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    use ApiResponse;
 
    
    /**
     * @OA\Post(
     *     path="/auth/login",
     *     tags={"Auth"},
     *     summary="Connexion utilisateur",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="admin@shop.com"),
     *             @OA\Property(property="password", type="string", example="StrongPass123!")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Connexion réussie",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="accessToken", type="string"),
     *                 @OA\Property(property="refreshToken", type="string"),
     *                 @OA\Property(property="user", ref="#/components/schemas/User")
     *             ),
     *             @OA\Property(property="meta", type="object", nullable=true),
     *             @OA\Property(property="timestamp", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Identifiants invalides", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password_hash)) {
            throw new ValidationException('Identifiants invalides', 'AUTH_INVALID_CREDENTIALS');
        }

        $user->load('boutique');

        $accessToken = JWTAuth::fromUser($user);

        JWTAuth::factory()->setTTL(config('jwt.refresh_ttl'));
        $refreshToken = JWTAuth::fromUser($user, ['token_type' => 'refresh']);
        JWTAuth::factory()->setTTL(config('jwt.ttl'));

        return $this->success([
            'accessToken'  => $accessToken,
            'refreshToken' => $refreshToken,
            'user'         => [
                'id'           => $user->id,
                'email'        => $user->email,
                'role'         => $user->role,
                'boutiqueId'   => $user->boutique_id,
                'boutiqueName' => $user->boutique?->nom ?? null,
            ],
        ]);
    }

    /**
     * @OA\Post(
     *     path="/auth/refresh",
     *     tags={"Auth"},
     *     summary="Rafraîchir le token d'accès",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"refreshToken"},
     *             @OA\Property(property="refreshToken", type="string")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Nouveau accessToken",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="accessToken", type="string")
     *             ),
     *             @OA\Property(property="meta", type="object", nullable=true),
     *             @OA\Property(property="timestamp", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(response=409, description="Token invalide", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refreshToken' => 'required|string']);

        try {
            $payload     = JWTAuth::setToken($request->refreshToken)->getPayload();
            $user        = User::findOrFail($payload->get('sub'));
            $accessToken = JWTAuth::fromUser($user);

            return $this->success(['accessToken' => $accessToken]);
        } catch (JWTException) {
            throw new ConflictException('Refresh token invalide', 'AUTH_INVALID_REFRESH');
        }
    }

    /**
     * @OA\Post(
     *     path="/auth/logout",
     *     tags={"Auth"},
     *     summary="Déconnexion",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Déconnecté", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function logout(): JsonResponse
    {
        return $this->success(['success' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($request->user());
    }

    /**
     * @OA\Post(
     *     path="/auth/forgot-password",
     *     tags={"Auth"},
     *     summary="Demander un lien de réinitialisation de mot de passe",
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"email"}, @OA\Property(property="email", type="string", format="email"))),
     *     @OA\Response(response=200, description="Lien envoyé si le compte existe", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        // Réponse générique dans tous les cas pour ne pas révéler si l'email existe.
        Password::sendResetLink($request->only('email'));

        return $this->success(['message' => 'Si un compte existe avec cet email, un lien de réinitialisation a été envoyé.']);
    }

    /**
     * @OA\Post(
     *     path="/auth/reset-password",
     *     tags={"Auth"},
     *     summary="Réinitialiser le mot de passe avec un token",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"email","token","password"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="token", type="string"),
     *             @OA\Property(property="password", type="string", minLength=8)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Mot de passe réinitialisé", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=422, description="Token invalide ou expiré", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill(['password_hash' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ValidationException(__($status), 'PASSWORD_RESET_FAILED');
        }

        return $this->success(['message' => 'Mot de passe réinitialisé avec succès.']);
    }
}
