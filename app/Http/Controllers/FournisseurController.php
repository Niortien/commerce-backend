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

    public function index(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $q = Fournisseur::where('boutique_id', $boutiqueId)->orderBy('nom');
        if ($request->filled('search')) $q->where('nom', 'like', '%' . $request->search . '%');
        return $this->success($q->get());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $fournisseur = Fournisseur::where('boutique_id', $boutiqueId)->with('entrees')->find($id);
        if (!$fournisseur) throw new NotFoundException('Fournisseur introuvable', 'FOURNISSEUR_NOT_FOUND');
        return $this->success($fournisseur);
    }

    public function store(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);

        $data = $request->validate([
            'nom'       => 'required|string',
            'telephone' => 'sometimes|nullable|string',
            'adresse'   => 'sometimes|nullable|string',
            'notes'     => 'sometimes|nullable|string',
        ]);

        if (Fournisseur::where('boutique_id', $boutiqueId)->where('nom', $data['nom'])->exists()) {
            throw new ConflictException('Un fournisseur avec ce nom existe déjà', 'FOURNISSEUR_NOM_TAKEN');
        }

        $fournisseur = Fournisseur::create(['boutique_id' => $boutiqueId, ...$data]);
        return $this->success($fournisseur, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $fournisseur = Fournisseur::where('boutique_id', $boutiqueId)->find($id);
        if (!$fournisseur) throw new NotFoundException('Fournisseur introuvable', 'FOURNISSEUR_NOT_FOUND');

        $data = $request->validate([
            'nom'       => 'sometimes|string',
            'telephone' => 'sometimes|nullable|string',
            'adresse'   => 'sometimes|nullable|string',
            'notes'     => 'sometimes|nullable|string',
        ]);

        if (isset($data['nom']) && Fournisseur::where('boutique_id', $boutiqueId)->where('nom', $data['nom'])->where('id', '!=', $id)->exists()) {
            throw new ConflictException('Un fournisseur avec ce nom existe déjà', 'FOURNISSEUR_NOM_TAKEN');
        }

        $fournisseur->update($data);
        return $this->success($fournisseur->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $fournisseur = Fournisseur::where('boutique_id', $boutiqueId)->find($id);
        if (!$fournisseur) throw new NotFoundException('Fournisseur introuvable', 'FOURNISSEUR_NOT_FOUND');

        if ($fournisseur->entrees()->exists()) {
            throw new ConflictException('Fournisseur lié à des entrées existantes', 'FOURNISSEUR_HAS_ENTREES');
        }

        $fournisseur->delete();
        return $this->success(['message' => 'Fournisseur supprimé', 'id' => $id]);
    }
}
