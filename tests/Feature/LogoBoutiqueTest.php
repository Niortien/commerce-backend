<?php

namespace Tests\Feature;

use App\Services\CloudinaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Logo de « Ma boutique » : le front l'envoie en image encodée, bien trop longue pour la colonne
 * logo_url (255 caractères). Il doit partir sur Cloudinary, et seule son adresse est enregistrée.
 */
class LogoBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private const LOGO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private function adminActif()
    {
        $admin = $this->actingAsAdmin();
        $admin->boutique->update(['statut' => 'ACTIF', 'is_active' => true]);
        return $admin;
    }

    public function test_le_logo_encode_part_sur_cloudinary_et_seule_son_adresse_est_gardee(): void
    {
        $admin = $this->adminActif();
        // Un vrai logo encodé dépasse largement les 255 caractères de la colonne.
        $logo = self::LOGO . str_repeat('A', 40000);
        $this->mock(CloudinaryService::class, function (MockInterface $m) use ($logo) {
            $m->shouldReceive('uploadBase64')->once()->with($logo, 'logos')
                ->andReturn('https://res.cloudinary.com/demo/image/upload/v1/logos/abc.png');
        });

        $this->patchJson('/api/v1/boutiques/me', ['logoUrl' => $logo])
            ->assertOk()
            ->assertJsonPath('data.logoUrl', 'https://res.cloudinary.com/demo/image/upload/v1/logos/abc.png');

        $this->assertSame('https://res.cloudinary.com/demo/image/upload/v1/logos/abc.png', $admin->boutique->fresh()->logo_url);
    }

    public function test_remplacer_le_logo_supprime_l_ancien_de_cloudinary(): void
    {
        $admin = $this->adminActif();
        $admin->boutique->update(['logo_url' => 'https://res.cloudinary.com/demo/image/upload/v1/logos/ancien.png']);
        $this->mock(CloudinaryService::class, function (MockInterface $m) {
            $m->shouldReceive('uploadBase64')->once()->andReturn('https://res.cloudinary.com/demo/image/upload/v1/logos/nouveau.png');
            $m->shouldReceive('deleteByUrl')->once()->with('https://res.cloudinary.com/demo/image/upload/v1/logos/ancien.png');
        });

        $this->patchJson('/api/v1/boutiques/me', ['logoUrl' => self::LOGO])->assertOk();
    }

    public function test_une_chaine_vide_retire_le_logo(): void
    {
        $admin = $this->adminActif();
        $admin->boutique->update(['logo_url' => 'https://res.cloudinary.com/demo/image/upload/v1/logos/ancien.png']);
        $this->mock(CloudinaryService::class, function (MockInterface $m) {
            $m->shouldNotReceive('uploadBase64');
            $m->shouldReceive('deleteByUrl')->once();
        });

        $this->patchJson('/api/v1/boutiques/me', ['logoUrl' => ''])->assertOk();
        $this->assertNull($admin->boutique->fresh()->logo_url);
    }

    public function test_un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        $this->adminActif();
        $this->mock(CloudinaryService::class, fn (MockInterface $m) => $m->shouldNotReceive('uploadBase64'));

        $this->patchJson('/api/v1/boutiques/me', ['logoUrl' => 'data:application/pdf;base64,JVBERi0xLjQK'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LOGO_FORMAT_INVALIDE');
    }

    public function test_un_echec_de_cloudinary_renvoie_un_message_clair_sans_toucher_au_logo(): void
    {
        $admin = $this->adminActif();
        $admin->boutique->update(['logo_url' => 'https://res.cloudinary.com/demo/image/upload/v1/logos/ancien.png']);
        $this->mock(CloudinaryService::class, function (MockInterface $m) {
            $m->shouldReceive('uploadBase64')->once()->andThrow(new \RuntimeException('Cloudinary upload failed: timeout'));
            $m->shouldNotReceive('deleteByUrl');
        });

        $this->patchJson('/api/v1/boutiques/me', ['logoUrl' => self::LOGO])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LOGO_ENVOI_ECHOUE');

        $this->assertSame('https://res.cloudinary.com/demo/image/upload/v1/logos/ancien.png', $admin->boutique->fresh()->logo_url);
    }
}
