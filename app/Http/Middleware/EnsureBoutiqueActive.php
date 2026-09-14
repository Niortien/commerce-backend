<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;

/**
 * Bloque l'accès aux routes métier d'une boutique dont l'abonnement est
 * expiré ou dont l'accès a été suspendu/archivé par le Super Admin.
 * Le SUPER_ADMIN (pas de boutique_id) n'est pas concerné.
 */
class EnsureBoutiqueActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user && $user->role !== 'SUPER_ADMIN' && $user->boutique_id) {
            $boutique = $user->boutique;
            if (!$boutique || !$boutique->accesAutorise()) {
                throw new DomainException(
                    "L'accès de votre boutique est suspendu. Contactez le support pour régulariser votre abonnement.",
                    403,
                    'BOUTIQUE_ACCESS_SUSPENDED'
                );
            }
        }

        return $next($request);
    }
}
