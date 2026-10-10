<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Traits\ApiResponse;
use App\Models\Produit;
use App\Models\Recette;
use App\Models\Variante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fiche technique d'un plat (restaurant) : ingrédients et quantités pour une portion.
 * Vendre le plat retire ces quantités du stock des ingrédients (voir SortieService).
 */
class RecetteController extends Controller
{
    use ApiResponse;

    private function findPlat(Request $request, string $id): Produit
    {
        $plat = Produit::where('boutique_id', $this->tenantBoutiqueId($request))->find($id);
        if (!$plat) throw new NotFoundException('Plat introuvable', 'PRODUIT_NOT_FOUND');
        return $plat;
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $plat = $this->findPlat($request, $id);
        return $this->success($plat->recette()->with('ingredient.produit')->get());
    }

    /** Remplace toute la fiche technique (liste complète des ingrédients). */
    public function update(Request $request, string $id): JsonResponse
    {
        $plat = $this->findPlat($request, $id);
        if ($plat->nature !== 'PLAT') {
            throw new ValidationException("« {$plat->nom} » n'est pas un plat : seul un plat a une fiche technique", 'PRODUIT_PAS_UN_PLAT');
        }

        $data = $request->validate([
            'lignes'               => 'present|array',
            'lignes.*.varianteId'  => 'required|uuid|distinct',
            'lignes.*.quantite'    => 'required|numeric|min:0.001',
        ]);

        $ingredients = Variante::with('produit')
            ->where('boutique_id', $plat->boutique_id)
            ->whereIn('id', array_column($data['lignes'], 'varianteId'))
            ->get()
            ->keyBy('id');

        foreach ($data['lignes'] as $l) {
            $variante = $ingredients->get($l['varianteId']);
            if (!$variante) throw new NotFoundException('Ingrédient introuvable', 'VARIANTE_NOT_FOUND');
            if ($variante->produit_id === $plat->id || $variante->produit?->nature === 'PLAT') {
                throw new ValidationException('Un plat ne peut pas servir d\'ingrédient', 'INGREDIENT_INVALIDE');
            }
        }

        DB::transaction(function () use ($plat, $data) {
            $plat->recette()->delete();
            foreach ($data['lignes'] as $l) {
                Recette::create([
                    'plat_id'                => $plat->id,
                    'ingredient_variante_id' => $l['varianteId'],
                    'quantite'               => $l['quantite'],
                ]);
            }
        });

        return $this->success($plat->recette()->with('ingredient.produit')->get());
    }
}
