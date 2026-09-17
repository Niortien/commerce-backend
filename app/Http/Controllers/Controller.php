<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Models\Boutique;
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

    /**
     * Comme tenantBoutiqueId, mais autorise en plus le SUPER_ADMIN à consulter
     * n'importe quelle boutique via ?boutiqueId=... — réservé aux endpoints de
     * LECTURE seule (rapports, stock en consultation). Ne jamais utiliser ceci
     * sur un endpoint d'écriture : un ADMIN/CAISSIER reste strictement limité
     * à sa propre boutique via tenantBoutiqueId.
     */
    protected function resolveBoutiqueId(Request $request): string
    {
        $user = $request->user();

        if ($user && $user->role === 'SUPER_ADMIN') {
            $boutiqueId = $request->get('boutiqueId');
            if (!$boutiqueId) {
                throw new DomainException('Le paramètre boutiqueId est requis.', 422, 'BOUTIQUE_ID_REQUIRED');
            }
            if (!Boutique::where('id', $boutiqueId)->exists()) {
                throw new NotFoundException('Boutique introuvable', 'BOUTIQUE_NOT_FOUND');
            }
            return $boutiqueId;
        }

        return $this->tenantBoutiqueId($request);
    }
}
