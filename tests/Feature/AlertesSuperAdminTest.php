<?php

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\User;
use App\Notifications\AbonnementTermine;
use App\Notifications\NouvelleInscriptionBoutique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Mails aux Super Admins : à chaque inscription et à chaque fin d'abonnement (une seule fois). */
class AlertesSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private function abonnementTermine(Boutique $boutique, string $plan = 'ESSAI'): Abonnement
    {
        Abonnement::where('boutique_id', $boutique->id)->delete();
        return Abonnement::create([
            'boutique_id' => $boutique->id, 'plan' => $plan, 'statut' => 'ACTIF',
            'date_debut' => now()->subDays(20), 'date_fin' => now()->subHour(),
        ]);
    }

    public function test_une_inscription_previent_les_super_admins(): void
    {
        Notification::fake();
        $super = User::factory()->superAdmin()->create();

        $this->postJson('/api/v1/auth/inscription-boutique', [
            'typeCommerce' => 'FRIPERIE',
            'nomBoutique'  => 'Fripe du Plateau',
            'email'        => 'fripe@example.com',
            'password'     => 'motdepasse123',
        ])->assertStatus(201);

        Notification::assertSentTo($super, NouvelleInscriptionBoutique::class);
    }

    public function test_la_fin_d_abonnement_est_signalee_une_seule_fois(): void
    {
        Notification::fake();
        $super = User::factory()->superAdmin()->create();
        $admin = $this->actingAsAdmin();
        $admin->boutique->update(['statut' => 'ACTIF', 'is_active' => true]);
        $abonnement = $this->abonnementTermine($admin->boutique, 'MENSUEL');

        $this->getJson('/api/v1/boutiques/me');
        $this->getJson('/api/v1/boutiques/me');
        $this->artisan('abonnements:signaler-fins')->assertSuccessful();

        Notification::assertSentToTimes($super, AbonnementTermine::class, 1);
        $this->assertNotNull($abonnement->fresh()->fin_signalee_at);
        $this->assertSame('SUSPENDU', $admin->boutique->fresh()->statut);
    }

    public function test_la_commande_previent_meme_si_la_boutique_ne_se_connecte_plus(): void
    {
        Notification::fake();
        $super = User::factory()->superAdmin()->create();
        $endormie = Boutique::factory()->create(['statut' => 'ESSAI']);
        $this->abonnementTermine($endormie);

        // Renouvelée à temps : rien à signaler.
        $renouvelee = Boutique::factory()->create(['statut' => 'ACTIF']);
        $this->abonnementTermine($renouvelee, 'MENSUEL');
        Abonnement::create([
            'boutique_id' => $renouvelee->id, 'plan' => 'MENSUEL', 'statut' => 'ACTIF',
            'date_debut' => now(), 'date_fin' => now()->addMonth(),
        ]);

        $this->artisan('abonnements:signaler-fins')->assertSuccessful();
        $this->artisan('abonnements:signaler-fins')->assertSuccessful();

        Notification::assertSentToTimes($super, AbonnementTermine::class, 1);
    }

    public function test_les_mails_se_construisent(): void
    {
        $super = User::factory()->superAdmin()->create();
        $admin = User::factory()->admin()->create();
        $abonnement = $this->abonnementTermine($admin->boutique);

        $inscription = (string) (new NouvelleInscriptionBoutique($admin->boutique, $admin))->toMail($super)->render();
        $fin = (string) (new AbonnementTermine($abonnement))->toMail($super)->render();

        $this->assertStringContainsString($admin->boutique->nom, $inscription);
        $this->assertStringContainsString($admin->email, $fin);
        $this->assertStringContainsString('/super-admin/boutiques/' . $admin->boutique_id, $fin);
    }

    public function test_sans_super_admin_rien_ne_casse(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/inscription-boutique', [
            'nomBoutique' => 'Boutique seule', 'email' => 'seule@example.com', 'password' => 'motdepasse123',
        ])->assertStatus(201);

        Notification::assertNothingSent();
    }
}
