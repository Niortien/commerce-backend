<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;


class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Boutique-locataire du contexte courant. Chaque ADMIN et CAISSIER est
     * strictement rattaché à SA boutique : contrairement à l'ancien modèle
     * "un seul propriétaire, plusieurs points de vente", il n'existe plus de
     * moyen de choisir une autre boutique via un paramètre de requête.
     *
     * Réservé aux routes de gestion boutique (ADMIN/CAISSIER) : le SUPER_ADMIN
     * n'a pas de boutique et ne doit jamais appeler ces endpoints.
     */
    protected function tenantBoutiqueId(Request $request): string
    {
        $user = $request->user();
        if (!$user || !$user->boutique_id) {
            throw new DomainException('Aucune boutique associée à ce compte.', 403, 'NO_BOUTIQUE_CONTEXT');
        }
        return $user->boutique_id;
    }
}
