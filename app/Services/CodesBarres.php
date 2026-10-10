<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\Variante;

/** Un code-barres désigne une seule variante dans une boutique. */
class CodesBarres
{
    /** Nettoie la saisie (espaces du scanner) ; null si vide. */
    public static function normaliser(?string $code): ?string
    {
        $code = $code === null ? null : trim($code);
        return $code === '' ? null : $code;
    }

    public static function verifierLibre(string $boutiqueId, ?string $code, ?string $saufVarianteId = null): void
    {
        if ($code === null) return;
        $existante = Variante::with('produit:id,nom')
            ->where('boutique_id', $boutiqueId)
            ->where('code_barre', $code)
            ->when($saufVarianteId, fn($q) => $q->where('id', '!=', $saufVarianteId))
            ->first();
        if ($existante) {
            $nom = $existante->produit?->nom ?? 'un autre article';
            throw new ConflictException("Ce code-barres est déjà utilisé par « {$nom} »", 'CODE_BARRE_PRIS', ['varianteId' => $existante->id]);
        }
    }
}
