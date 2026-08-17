<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CamelCaseJsonResponse
{
    /**
     * Convertit les clés snake_case par regex sur la string JSON brute.
     * Évite decode PHP → traverse récursive → re-encode : O(n) sur le texte,
     * vs O(n × profondeur) sur l'arbre d'objets Eloquent.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $converted = preg_replace_callback(
                '/"([a-z][a-z0-9]*(?:_[a-z0-9]+)+)"\s*:/',
                fn(array $m) => '"' . lcfirst(str_replace('_', '', ucwords($m[1], '_'))) . '":',
                $response->getContent()
            );
            $response->setContent($converted ?? $response->getContent());
        }

        return $response;
    }
}
