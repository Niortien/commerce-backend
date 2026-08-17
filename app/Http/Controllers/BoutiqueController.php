<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\AuditLog;
use App\Models\Boutique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoutiqueController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(
     *     path="/boutiques",
     *     tags={"Boutiques"},
     *     summary="Liste toutes les boutiques (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Liste des boutiques",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Boutique")),
     *             @OA\Property(property="meta", type="object", nullable=true),
     *             @OA\Property(property="timestamp", type="string", format="date-time")
     *         )
     *     )
     * )
     */
    public function index(): JsonResponse
    {
        return $this->success(Boutique::orderBy('created_at')->get());
    }

    /**
     * @OA\Get(
     *     path="/boutiques/{id}",
     *     tags={"Boutiques"},
     *     summary="Détail d'une boutique (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Boutique trouvée", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show(string $id): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');
        return $this->success($b);
    }

    /**
     * @OA\Post(
     *     path="/boutiques",
     *     tags={"Boutiques"},
     *     summary="Créer une boutique (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"nom"},
     *             @OA\Property(property="nom", type="string", example="Boutique Centre"),
     *             @OA\Property(property="adresse", type="string", nullable=true),
     *             @OA\Property(property="ville", type="string", nullable=true),
     *             @OA\Property(property="whatsapp", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Boutique créée", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom'      => 'required|string',
            'adresse'  => 'sometimes|nullable|string',
            'ville'    => 'sometimes|nullable|string',
            'whatsapp' => 'sometimes|nullable|string',
        ]);
        return $this->success(Boutique::create($data), 201);
    }

    /**
     * @OA\Patch(
     *     path="/boutiques/{id}",
     *     tags={"Boutiques"},
     *     summary="Modifier une boutique (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="nom", type="string"),
     *             @OA\Property(property="adresse", type="string", nullable=true),
     *             @OA\Property(property="ville", type="string", nullable=true),
     *             @OA\Property(property="whatsapp", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Mis à jour", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $data = $request->validate([
            'nom'      => 'sometimes|string',
            'adresse'  => 'sometimes|nullable|string',
            'ville'    => 'sometimes|nullable|string',
            'whatsapp' => 'sometimes|nullable|string',
            'isActive' => 'sometimes|boolean',
        ]);
        if (array_key_exists('isActive', $data)) {
            $data['is_active'] = $data['isActive'];
            unset($data['isActive']);
        }
        $b->update($data);
        return $this->success($b->fresh());
    }

    /**
     * @OA\Delete(
     *     path="/boutiques/{id}",
     *     tags={"Boutiques"},
     *     summary="Supprimer une boutique (ADMIN)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Supprimée", @OA\JsonContent(ref="#/components/schemas/ApiResponse")),
     *     @OA\Response(response=404, description="Introuvable", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $b = Boutique::find($id);
        if (!$b) throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');

        $aDesDonnees = $b->entrees()->exists() || $b->sorties()->exists() || $b->caisseSessions()->exists();

        if ($aDesDonnees) {
            if (!$b->is_active) {
                throw new ConflictException('Boutique déjà archivée', 'BOUTIQUE_ALREADY_ARCHIVED');
            }
            $b->update(['is_active' => false]);
            AuditLog::record($request->user()->id, 'BOUTIQUE_ARCHIVE', 'Boutique', $b->id, "Archivage boutique {$b->nom} (données historiques conservées)");
            return $this->success($b->fresh());
        }

        $b->delete();
        AuditLog::record($request->user()->id, 'BOUTIQUE_DESTROY', 'Boutique', $id, "Suppression boutique {$b->nom}");
        return $this->success($b);
    }
}
