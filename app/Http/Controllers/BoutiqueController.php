<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Http\Traits\ApiResponse;
use App\Models\Boutique;
use App\Services\CloudinaryService;
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

    /** Taille maximale du logo encodé reçu (≈ 1,5 Mo d'image) : le front l'envoie déjà réduit à 256 px. */
    private const LOGO_DATA_URL_MAX = 2_000_000;

    public function __construct(private CloudinaryService $cloudinary) {}

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
            'logoUrl'  => 'sometimes|nullable|string|max:' . self::LOGO_DATA_URL_MAX,
            'vitrineActive' => 'sometimes|boolean',
        ]);
        $ancienLogo = $boutique->logo_url;
        if (array_key_exists('logoUrl', $data)) {
            $data['logo_url'] = $this->logoAEnregistrer($data['logoUrl']);
            unset($data['logoUrl']);
        }
        if (array_key_exists('vitrineActive', $data)) {
            $data['vitrine_active'] = $data['vitrineActive'];
            unset($data['vitrineActive']);
        }

        // Le type de commerce décide des pages de la boutique : seul le Super Admin le change.
        if ($request->has('typeCommerce')) {
            throw new \App\Exceptions\DomainException(
                'Seul le Super Admin peut changer le type de commerce de la boutique',
                403,
                'TYPE_COMMERCE_RESERVE'
            );
        }

        $boutique->update($data);

        // L'ancien logo n'est retiré de Cloudinary qu'une fois le nouveau bien enregistré.
        if (array_key_exists('logo_url', $data) && $ancienLogo && $ancienLogo !== $data['logo_url']) {
            $this->cloudinary->deleteByUrl($ancienLogo);
        }

        return $this->success($boutique->fresh());
    }

    /**
     * Le front envoie le logo en image encodée (data URL, des dizaines de milliers de caractères) :
     * il part sur Cloudinary et seule son adresse est gardée en base (colonne de 255 caractères).
     * Une chaîne vide retire le logo.
     */
    private function logoAEnregistrer(?string $logo): ?string
    {
        if ($logo === null || $logo === '') {
            return null;
        }

        if (!str_starts_with($logo, 'data:')) {
            if (mb_strlen($logo) > 255 || filter_var($logo, FILTER_VALIDATE_URL) === false) {
                throw new DomainException("L'adresse du logo n'est pas valide.", 422, 'LOGO_INVALIDE');
            }
            return $logo;
        }

        if (!preg_match('#^data:image/(png|jpe?g|webp);base64,#', $logo)) {
            throw new DomainException('Le logo doit être une image PNG, JPEG ou WebP.', 422, 'LOGO_FORMAT_INVALIDE');
        }

        try {
            return $this->cloudinary->uploadBase64($logo, 'logos');
        } catch (\RuntimeException $e) {
            report($e);
            throw new DomainException("Le logo n'a pas pu être envoyé. Réessayez dans un instant.", 422, 'LOGO_ENVOI_ECHOUE');
        }
    }
}
