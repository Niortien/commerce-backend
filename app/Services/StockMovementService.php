<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\MouvementStock;
use App\Models\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockMovementService
{
    public function create(
        string $varianteId,
        string $type,
        int $quantite,
        string $userId,
        ?string $motif = null,
        ?string $referenceEntree = null,
        ?string $referenceSortie = null,
    ): MouvementStock {
        return DB::transaction(function () use ($varianteId, $type, $quantite, $userId, $motif, $referenceEntree, $referenceSortie) {
            $variante = Variante::where('id', $varianteId)->lockForUpdate()->firstOrFail();

            $sign = in_array($type, ['ENTREE', 'RETOUR', 'AJUSTEMENT'], true) ? 1 : -1;
            $newStock = $variante->quantite_stock + ($sign * $quantite);

            if ($newStock < 0) {
                throw new DomainException(
                    "Stock insuffisant pour la variante {$varianteId}",
                    409,
                    'STOCK_INSUFFISANT',
                    ['varianteId' => $varianteId, 'stockActuel' => $variante->quantite_stock, 'quantiteDemandee' => $quantite]
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

            if ($newStock <= $variante->seuil_alerte) {
                Log::warning("Stock en alerte: variante {$varianteId}, stock={$newStock}, seuil={$variante->seuil_alerte}");
            }

            return $mouvement;
        });
    }
}
