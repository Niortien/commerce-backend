<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;

/**
 * Réserve des routes à un type de commerce : devis et clients à crédit (quincaillerie), balles et démarque
 * (friperie), fiches techniques (restaurant). Une boutique d'un autre métier reçoit un refus, même en
 * appelant l'URL directement. Usage : `metier:QUINCAILLERIE` (plusieurs types séparés par des virgules).
 */
class EnsureMetier
{
    public function handle(Request $request, Closure $next, string ...$types): mixed
    {
        $user = $request->user();
        $type = $user?->boutique?->type_commerce;

        if (!$user || $user->role === 'SUPER_ADMIN' || !in_array($type, $types, true)) {
            throw new DomainException(
                "Cette fonction n'existe pas pour votre type de commerce.",
                403,
                'FONCTION_AUTRE_METIER'
            );
        }

        return $next($request);
    }
}
