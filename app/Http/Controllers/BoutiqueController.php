<?php

namespace App\Http\Controllers;

use App\Http\Traits\ApiResponse;
use App\Models\Boutique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profil de LA boutique courante (celle de l'utilisateur ADMIN/CAISSIER
 * connecté). La gestion transverse de toutes les boutiques appartient
 * exclusivement au Super Admin (voir SuperAdminBoutiqueController) —
 * un ADMIN de boutique ne doit plus pouvoir lister ou modifier une autre
 * boutique que la sienne.
 */
class BoutiqueController extends Controller
{
    use ApiResponse;

    public function me(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $boutique = Boutique::findOrFail($boutiqueId);

        $data = $boutique->toArray();
        $data['abonnementActif'] = $boutique->abonnementActif();

        return $this->success($data);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $boutiqueId = $this->tenantBoutiqueId($request);
        $boutique = Boutique::findOrFail($boutiqueId);

        $data = $request->validate([
            'nom'      => 'sometimes|string|max:150',
            'adresse'  => 'sometimes|nullable|string',
            'ville'    => 'sometimes|nullable|string',
            'whatsapp' => 'sometimes|nullable|string',
            'email'    => 'sometimes|nullable|email',
            'telephone'=> 'sometimes|nullable|string',
            'logoUrl'  => 'sometimes|nullable|string',
        ]);
        if (array_key_exists('logoUrl', $data)) {
            $data['logo_url'] = $data['logoUrl'];
            unset($data['logoUrl']);
        }

        $boutique->update($data);
        return $this->success($boutique->fresh());
    }
}
