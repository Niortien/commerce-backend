<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;

/**
 * Bloque l'accès aux routes métier d'une boutique dont l'abonnement est
 * expiré ou dont l'accès a été suspendu/archivé par le Super Admin.
 * Le SUPER_ADMIN (pas de boutique_id) n'est pas concerné.
 *
 * La réponse 403 BOUTIQUE_ACCESS_SUSPENDED porte la raison exacte (message lisible + détails)
 * pour que l'espace boutique l'affiche au lieu d'une erreur technique.
 */
class EnsureBoutiqueActive
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user && $user->role !== 'SUPER_ADMIN' && $user->boutique_id && !ConsultationLectureSeule::estConsultation()) {
            $boutique = $user->boutique;
            if (!$boutique) {
                throw new DomainException(
                    "La boutique de ce compte n'existe plus. Contactez le support.",
                    403,
                    'BOUTIQUE_ACCESS_SUSPENDED',
                    ['statut' => null, 'cause' => null, 'motif' => null, 'dateFin' => null]
                );
            }

            if (!$boutique->accesAutorise()) {
                $abonnement = $boutique->abonnementActif();
                throw new DomainException(
                    $boutique->messageAccesRefuse() ?? "L'accès de votre boutique est suspendu. Contactez le support.",
                    403,
                    'BOUTIQUE_ACCESS_SUSPENDED',
                    [
                        'statut'  => $boutique->statut,
                        'cause'   => $boutique->suspension_cause,
                        'motif'   => $boutique->suspension_motif,
                        'dateFin' => $abonnement?->date_fin?->toISOString(),
                    ]
                );
            }
        }

        return $next($request);
    }
}
