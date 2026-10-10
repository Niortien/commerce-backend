<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Le Super Admin ouvre l'espace d'un admin ou d'un caissier pour voir exactement ce qu'il voit.
 *
 * Il reçoit un jeton au nom de ce compte, valable une heure, sans jeton de rafraîchissement, et marqué
 * « lecture_seule » : le middleware ConsultationLectureSeule refuse toute modification. Chaque ouverture
 * est inscrite au journal d'audit.
 */
class ConsultationController extends Controller
{
    use ApiResponse;

    /** Durée d'une consultation, en minutes. */
    private const DUREE_MINUTES = 60;

    /** Ouvrir l'espace d'un compte précis (page Utilisateurs). */
    public function utilisateur(Request $request, string $id): JsonResponse
    {
        $cible = User::with('boutique')->find($id);
        if (!$cible) throw new NotFoundException('Utilisateur introuvable', 'USER_NOT_FOUND');

        return $this->ouvrir($request, $cible);
    }

    /** Ouvrir l'espace admin ou caissier d'une boutique (page Boutiques). */
    public function boutique(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['role' => 'required|in:ADMIN,CAISSIER']);
        $boutique = Boutique::find($id);
        if (!$boutique) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $cible = User::with('boutique')
            ->where('boutique_id', $boutique->id)
            ->where('role', $data['role'])
            ->orderBy('created_at')
            ->first();
        if (!$cible) {
            $qui = $data['role'] === 'ADMIN' ? "d'administrateur" : 'de caissier';
            throw new NotFoundException("{$boutique->nom} n'a pas encore {$qui}", 'CONSULTATION_SANS_COMPTE');
        }

        return $this->ouvrir($request, $cible);
    }

    private function ouvrir(Request $request, User $cible): JsonResponse
    {
        if (!in_array($cible->role, ['ADMIN', 'CAISSIER'], true) || !$cible->boutique_id) {
            throw new ValidationException("Seuls les espaces d'un admin ou d'un caissier de boutique se consultent", 'CONSULTATION_IMPOSSIBLE');
        }

        $superAdmin = $request->user();
        $ttl = JWTAuth::factory()->getTTL();
        JWTAuth::factory()->setTTL(self::DUREE_MINUTES);
        $jeton = JWTAuth::claims(['consultation' => $superAdmin->id, 'lecture_seule' => true])->fromUser($cible);
        JWTAuth::factory()->setTTL($ttl);

        AuditLog::record(
            $superAdmin->id,
            'CONSULTATION_ESPACE',
            // Rattachée à la boutique : la consultation apparaît quand on filtre le journal sur elle.
            'Boutique',
            $cible->boutique_id,
            "Consultation de l'espace {$this->libelleRole($cible->role)} de {$cible->email} — {$cible->boutique?->nom}"
        );

        return $this->success([
            'accessToken' => $jeton,
            'expireDans'  => self::DUREE_MINUTES * 60,
            'user'        => [
                'id'             => $cible->id,
                'email'          => $cible->email,
                'role'           => $cible->role,
                'boutiqueId'     => $cible->boutique_id,
                'boutiqueName'   => $cible->boutique?->nom,
                'boutiqueStatut' => $cible->boutique?->statut,
            ],
        ]);
    }

    private function libelleRole(string $role): string
    {
        return $role === 'ADMIN' ? 'admin' : 'caissier';
    }
}
