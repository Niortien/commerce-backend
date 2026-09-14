<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Variante;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VarianteController extends Controller
{
    use ApiResponse;

    public function __construct(private StockMovementService $movements) {}

    private function findOwned(Request $request, string $id): Variante
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $variante = Variante::where('boutique_id', $boutiqueId)->find($id);
        if (!$variante) throw new NotFoundException('Variante introuvable', 'VARIANTE_NOT_FOUND');
        return $variante;
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $variante = $this->findOwned($request, $id);

        $data = $request->validate([
            'taille'      => 'sometimes|string',
            'couleur'     => 'sometimes|string',
            'seuilAlerte' => 'sometimes|integer|min:0',
        ]);

        $map = ['taille' => 'taille', 'couleur' => 'couleur', 'seuilAlerte' => 'seuil_alerte'];
        $update = [];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data)) $update[$to] = $data[$from];
        }

        if ((isset($update['taille']) || isset($update['couleur']))) {
            $conflit = Variante::where('produit_id', $variante->produit_id)
                ->where('taille', $update['taille'] ?? $variante->taille)
                ->where('couleur', $update['couleur'] ?? $variante->couleur)
                ->where('id', '!=', $variante->id)
                ->exists();

            if ($conflit) {
                throw new ConflictException(
                    'Une variante identique (taille/couleur) existe déjà pour ce produit',
                    'VARIANTE_CONFLICT'
                );
            }
        }

        $variante->update($update);
        return $this->success($variante->fresh()->load('produit'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $variante = $this->findOwned($request, $id);

        if ($variante->mouvements()->exists()) {
            throw new ConflictException(
                'Impossible de supprimer une variante ayant des mouvements de stock',
                'VARIANTE_HAS_MOVEMENTS'
            );
        }

        $variante->delete();
        return $this->success($variante);
    }

    public function adjustStock(Request $request, string $id): JsonResponse
    {
        $variante = $this->findOwned($request, $id);

        $data = $request->validate([
            'variation' => 'required|integer',
            'motif'     => 'sometimes|nullable|string',
        ]);

        $variation = (int) $data['variation'];
        $type      = $variation >= 0 ? 'AJUSTEMENT' : 'SORTIE';
        $quantite  = abs($variation);

        $mouvement = $this->movements->create(
            $variante->id, $type, $quantite, $request->user()->id, $data['motif'] ?? null
        );

        return $this->success($mouvement->load('variante'));
    }
}
