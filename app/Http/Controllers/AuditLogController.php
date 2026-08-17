<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(path="/audit-logs", tags={"Audit"}, summary="Journal des actions sensibles (ADMIN)", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="action", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="entityType", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="dateDebut", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="dateFin", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Journal d'audit", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $q = AuditLog::with('user')->orderBy('created_at', 'desc');

        if ($request->filled('action'))     $q->where('action', $request->action);
        if ($request->filled('entityType')) $q->where('entity_type', $request->entityType);
        if ($request->filled('dateDebut'))  $q->where('created_at', '>=', $request->dateDebut);
        if ($request->filled('dateFin'))    $q->where('created_at', '<=', $request->dateFin);

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $data  = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($data, $total, $page, $limit);
    }
}
