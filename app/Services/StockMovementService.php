<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\MouvementStock;
use App\Models\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockMovementService
{
    /** Les quantités sont décimales (12,5 m, 0,250 kg) : calculs exacts au millième, sans flottants. */
    private const ECHELLE = 3;

    public function create(
        string $varianteId,
        string $type,
        int|float|string $quantite,
        string $userId,
        ?string $motif = null,
        ?string $referenceEntree = null,
        ?string $referenceSortie = null,
    ): MouvementStock {
        $quantite = number_format((float) $quantite, self::ECHELLE, '.', '');

        return DB::transaction(function () use ($varianteId, $type, $quantite, $userId, $motif, $referenceEntree, $referenceSortie) {
            $variante = Variante::with('produit')->where('id', $varianteId)->lockForUpdate()->firstOrFail();

            $stockActuel = number_format((float) $variante->quantite_stock, self::ECHELLE, '.', '');
            $entrant = in_array($type, ['ENTREE', 'RETOUR', 'AJUSTEMENT'], true);
            $newStock = $entrant
                ? bcadd($stockActuel, $quantite, self::ECHELLE)
                : bcsub($stockActuel, $quantite, self::ECHELLE);

            $nom = $variante->produit?->nom ?? 'cet article';
            if ($variante->produit?->piece_unique) {
                // Friperie : une pièce unique est en rayon (1) ou partie (0), jamais plus.
                if (bccomp($newStock, '0', self::ECHELLE) < 0) {
                    throw new DomainException("« {$nom} » est une pièce unique déjà vendue", 409, 'PIECE_DEJA_VENDUE', ['varianteId' => $varianteId]);
                }
                if (bccomp($newStock, '1', self::ECHELLE) > 0) {
                    throw new DomainException("« {$nom} » est une pièce unique : elle est déjà en rayon", 409, 'PIECE_DEJA_EN_RAYON', ['varianteId' => $varianteId]);
                }
            }

            if (bccomp($newStock, '0', self::ECHELLE) < 0) {
                throw new DomainException(
                    "Stock insuffisant : {$nom} (reste " . $this->lisible($stockActuel) . ', il en faut ' . $this->lisible($quantite) . ')',
                    409,
                    'STOCK_INSUFFISANT',
                    ['varianteId' => $varianteId, 'stockActuel' => (float) $stockActuel, 'quantiteDemandee' => (float) $quantite]
                );
            }

            $variante->update(['quantite_stock' => $newStock]);

            $mouvement = MouvementStock::create([
                'variante_id'       => $varianteId,
                'type'              => $type,
                'quantite'          => $quantite,
                'motif'             => $motif,
                'reference_entree'  => $referenceEntree,
                'reference_sortie'  => $referenceSortie,
                'user_id'           => $userId,
            ]);

            if (bccomp($newStock, number_format((float) $variante->seuil_alerte, self::ECHELLE, '.', ''), self::ECHELLE) <= 0) {
                Log::warning("Stock en alerte: variante {$varianteId}, stock={$newStock}, seuil={$variante->seuil_alerte}");
            }

            return $mouvement;
        });
    }

    /** "12.500" → "12,5" pour les messages. */
    private function lisible(string $quantite): string
    {
        return str_replace('.', ',', rtrim(rtrim($quantite, '0'), '.'));
    }
}
