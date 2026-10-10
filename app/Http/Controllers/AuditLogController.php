<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'audit : connexions, consultations du Super Admin, suppressions, annulations, changements
 * d'abonnement ou de type de commerce… Le Super Admin voit toute la plateforme ; un admin, sa boutique.
 */
class AuditLogController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(path="/super-admin/audit-logs", tags={"Audit"}, summary="Journal des actions sensibles", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="action", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="boutiqueId", in="query", @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="role", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Journal d'audit", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action'     => 'sometimes|nullable|string|max:60',
            'entityType' => 'sometimes|nullable|string|max:60',
            'boutiqueId' => 'sometimes|nullable|uuid',
            'role'       => 'sometimes|nullable|in:SUPER_ADMIN,ADMIN,CAISSIER',
            'search'     => 'sometimes|nullable|string|max:150',
            'dateDebut'  => 'sometimes|nullable|date',
            'dateFin'    => 'sometimes|nullable|date',
            'page'       => 'sometimes|integer|min:1',
            'limit'      => 'sometimes|integer|min:1|max:100',
        ]);

        $q = AuditLog::with(['user:id,email,role,boutique_id', 'user.boutique:id,nom,type_commerce'])
            ->orderBy('created_at', 'desc');

        $user = $request->user();
        $boutiqueId = $user->role === 'SUPER_ADMIN' ? ($data['boutiqueId'] ?? null) : $user->boutique_id;
        if ($boutiqueId) {
            // Ce qu'ont fait les comptes de la boutique, et ce qui a été fait sur la boutique elle-même.
            $q->where(fn(Builder $w) => $w
                ->whereHas('user', fn($u) => $u->where('boutique_id', $boutiqueId))
                ->orWhere(fn($e) => $e->where('entity_type', 'Boutique')->where('entity_id', $boutiqueId)));
        }

        if (!empty($data['action']))     $q->where('action', $data['action']);
        if (!empty($data['entityType'])) $q->where('entity_type', $data['entityType']);
        if (!empty($data['role']))       $q->whereHas('user', fn($u) => $u->where('role', $data['role']));
        if (!empty($data['dateDebut']))  $q->where('created_at', '>=', $data['dateDebut']);
        // La date de fin compte toute la journée.
        if (!empty($data['dateFin']))    $q->where('created_at', '<', date('Y-m-d', strtotime($data['dateFin'] . ' +1 day')));
        if (!empty($data['search'])) {
            $s = $data['search'];
            $q->where(fn(Builder $w) => $w
                ->where('description', 'like', "%{$s}%")
                ->orWhereHas('user', fn($u) => $u->where('email', 'like', "%{$s}%")));
        }

        $page  = (int) ($data['page'] ?? 1);
        $limit = (int) ($data['limit'] ?? 30);
        $total = $q->count();
        $logs  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($logs, $total, $page, $limit);
    }

    /** Les actions déjà présentes dans le journal, pour le filtre. */
    public function actions(): JsonResponse
    {
        return $this->success(AuditLog::query()->distinct()->orderBy('action')->pluck('action'));
    }
}
