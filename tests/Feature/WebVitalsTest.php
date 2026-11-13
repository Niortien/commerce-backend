<?php

namespace Tests\Feature;

use App\Models\WebVital;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vitesse réelle du site : les navigateurs envoient leurs mesures (sans donnée personnelle),
 * le Super Admin lit le 75e centile par page et par appareil.
 */
class WebVitalsTest extends TestCase
{
    use RefreshDatabase;

    private function envoyer(array $corps)
    {
        // Comme navigator.sendBeacon : du JSON envoyé en text/plain.
        return $this->call('POST', '/api/v1/web-vitals', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'], json_encode($corps));
    }

    public function test_les_mesures_d_une_visite_sont_enregistrees(): void
    {
        $this->envoyer([
            'page' => '/boutique/[slug]',
            'appareil' => 'mobile',
            'connexion' => '4g',
            'mesures' => [
                ['metrique' => 'LCP', 'valeur' => 2100.5, 'note' => 'good'],
                ['metrique' => 'CLS', 'valeur' => 0.02, 'note' => 'good'],
                ['metrique' => 'TTFB', 'valeur' => 420, 'note' => 'good'],
            ],
        ])->assertNoContent();

        $this->assertSame(3, WebVital::count());
        $this->assertSame('/boutique/[slug]', WebVital::first()->page);
    }

    public function test_une_mesure_invalide_est_refusee_sans_rien_enregistrer(): void
    {
        $this->envoyer([
            'page' => 'https://ailleurs.example/x',
            'appareil' => 'mobile',
            'mesures' => [['metrique' => 'LCP', 'valeur' => 2000, 'note' => 'good']],
        ])->assertStatus(422);
        $this->envoyer(['page' => '/', 'appareil' => 'tablette', 'mesures' => [['metrique' => 'XYZ', 'valeur' => -1, 'note' => 'top']]])
            ->assertStatus(422);

        $this->assertSame(0, WebVital::count());
    }

    public function test_le_super_admin_lit_le_75e_centile_par_page_et_appareil(): void
    {
        foreach ([1000, 2000, 3000, 4000, 5000] as $lcp) {
            WebVital::create(['metrique' => 'LCP', 'valeur' => $lcp, 'note' => $lcp <= 2500 ? 'good' : 'poor', 'page' => '/boutique/[slug]', 'appareil' => 'mobile', 'created_at' => now()]);
            WebVital::create(['metrique' => 'TTFB', 'valeur' => 300, 'note' => 'good', 'page' => '/boutique/[slug]', 'appareil' => 'mobile', 'created_at' => now()]);
        }
        // Trop ancienne pour la période demandée.
        WebVital::create(['metrique' => 'LCP', 'valeur' => 90000, 'note' => 'poor', 'page' => '/boutique/[slug]', 'appareil' => 'mobile', 'created_at' => now()->subDays(40)]);

        $this->actingAsSuperAdmin();
        $res = $this->getJson('/api/v1/super-admin/web-vitals?jours=28')->assertOk();

        $page = $res->json('data.parPage.0');
        $this->assertSame('/boutique/[slug]', $page['page']);
        $this->assertSame(5, $page['nbVisites']);
        $lcp = collect($page['metriques'])->firstWhere('metrique', 'LCP');
        $this->assertEquals(4000, $lcp['p75']);
        $this->assertEquals(0.4, $lcp['partBonne']);
    }

    public function test_un_admin_de_boutique_ne_lit_pas_les_mesures(): void
    {
        $this->actingAsAdmin();
        $this->getJson('/api/v1/super-admin/web-vitals')->assertForbidden();
    }
}
