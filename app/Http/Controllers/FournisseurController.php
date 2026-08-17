<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Fournisseur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FournisseurController extends Controller
{
    use ApiResponse;

    /**
     * @OA\Get(path="/fournisseurs", tags={"Fournisseurs"}, summary="Liste des fournisseurs", security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Fournisseurs", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $q = Fournisseur::query()->orderBy('nom');
        if ($request->filled('search')) $q->where('nom', 'like', '%' . $request->search . '%');
        return $this->success($q->get());
    }

    public function show(string $id): JsonResponse
    {
        $fournisseur = Fournisseur::with('entrees')->find($id);
        if (!$fournisseur) throw new NotFoundException('Fournisseur introuvable', 'FOURNISSEUR_NOT_FOUND');
        return $this->success($fournisseur);
    }

    /**
     * @OA\Post(path="/fournisseurs", tags={"Fournisseurs"}, summary="Créer un fournisseur", security={{"bearerAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(required={"nom"},
     *             @OA\Property(property="nom", type="string"),
     *             @OA\Property(property="telephone", type="string", nullable=true),
     *             @OA\Property(property="adresse", type="string", nullable=true),
     *             @OA\Property(property="notes", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Fournisseur créé", @OA\JsonContent(ref="#/components/schemas/ApiResponse"))
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom'       => 'required|string|unique:fournisseurs,nom',
            'telephone' => 'sometimes|nullable|string',
            'adresse'   => 'sometimes|nullable|string',
            'notes'     => 'sometimes|nullable|string',
        ]);

        $fournisseur = Fournisseur::create($data);
        return $this->success($fournisseur, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $fournisseur = Fournisseur::find($id);
        if (!$fournisseur) throw new NotFoundException('Fournisseur introuvable', 'FOURNISSEUR_NOT_FOUND');

        $data = $request->validate([
            'nom'       => 'sometimes|string|unique:fournisseurs,nom,' . $id,
            'telephone' => 'sometimes|nullable|string',
            'adresse'   => 'sometimes|nullable|string',
            'notes'     => 'sometimes|nullable|string',
        ]);

        $fournisseur->update($data);
        return $this->success($fournisseur->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $fournisseur = Fournisseur::find($id);
        if (!$fournisseur) throw new NotFoundException('Fournisseur introuvable', 'FOURNISSEUR_NOT_FOUND');

        if ($fournisseur->entrees()->exists()) {
            throw new ConflictException('Fournisseur lié à des entrées existantes', 'FOURNISSEUR_HAS_ENTREES');
        }

        $fournisseur->delete();
        return $this->success(['message' => 'Fournisseur supprimé', 'id' => $id]);
    }
}
