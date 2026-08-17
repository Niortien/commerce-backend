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
        ])->header('Cache-Control', 'private, max-age=60, must-revalidate');
    }
}
