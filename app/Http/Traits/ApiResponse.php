<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    protected function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data'      => $data,
            'meta'      => null,
            'timestamp' => now()->toISOString(),
        ], $status)->header('Cache-Control', 'no-store');
    }

    protected function paginated(mixed $data, int $total, int $page, int $limit): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                'total'     => $total,
                'page'      => $page,
                'limit'     => $limit,
                'pageCount' => (int) ceil($total / $limit),
            ],
            'timestamp' => now()->toISOString(),
        // Pas de cache navigateur : une liste relue juste après une modification doit être à jour.
        ])->header('Cache-Control', 'no-store');
    }
}
