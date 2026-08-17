<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Categorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategorieController extends Controller
{
    use ApiResponse;

    private const GROUPES = [
        'Hauts', 'Chemises & Vestes', 'Tenues', 'Pulls & Maillots',
        'Bas', 'Culotte', 'Chaussures', 'Sacs & Divers', 'Parfum & Bijoux',
    ];

    public function index(): JsonResponse
    {
        $categories = Cache::remember('categories.all', 3600, fn () =>
            Categorie::orderBy('description')->orderBy('nom')->get()
        );
        return $this->success($categories);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nom'         => 'required|string|max:100',
            'description' => 'required|string|in:' . implode(',', self::GROUPES),
            'slug'        => 'sometimes|nullable|string|max:100|unique:categories,slug',
        ]);

        $slug = $data['slug'] ?? Str::slug($data['nom']);

        if (Categorie::where('slug', $slug)->exists()) {
            throw new ConflictException(
                "Le slug \"{$slug}\" est déjà utilisé",
                'CATEGORIE_SLUG_TAKEN'
            );
        }

        $categorie = Categorie::create([
            'nom'         => $data['nom'],
            'slug'        => $slug,
            'description' => $data['description'],
        ]);

        Cache::forget('categories.all');

        return $this->success($categorie, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $categorie = Categorie::find($id);
        if (!$categorie) throw new NotFoundException('Catégorie introuvable', 'CATEGORIE_NOT_FOUND');

        $data = $request->validate([
            'nom'         => 'sometimes|string|max:100',
            'description' => 'sometimes|string|in:' . implode(',', self::GROUPES),
            'slug'        => 'sometimes|string|max:100|unique:categories,slug,' . $id,
        ]);

        if (isset($data['nom']) && !isset($data['slug'])) {
            $newSlug = Str::slug($data['nom']);
            if ($newSlug !== $categorie->slug) {
                if (Categorie::where('slug', $newSlug)->where('id', '!=', $id)->exists()) {
                    throw new ConflictException(
                        "Le slug \"{$newSlug}\" est déjà utilisé",
                        'CATEGORIE_SLUG_TAKEN'
                    );
                }
                $data['slug'] = $newSlug;
            }
        }

        $categorie->update($data);
        Cache::forget('categories.all');

        return $this->success($categorie->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $categorie = Categorie::find($id);
        if (!$categorie) throw new NotFoundException('Catégorie introuvable', 'CATEGORIE_NOT_FOUND');

        if ($categorie->produits()->exists()) {
            throw new ConflictException(
                'Impossible de supprimer une catégorie liée à des produits',
                'CATEGORIE_HAS_PRODUITS'
            );
        }

        $categorie->delete();
        Cache::forget('categories.all');

        return $this->success($categorie);
    }
}
