<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Boutique;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Le Super Admin ouvre l'espace d'un admin ou d'un caissier en lecture seule ; chaque ouverture,
 * comme chaque connexion, est inscrite au journal d'audit.
 */
class ConsultationEtAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Passe à un autre jeton. Dans un test, l'application vit d'une requête à l'autre : le garde et le
     * service JWT gardent sinon l'utilisateur et le jeton de la requête précédente.
     */
    private function oublierIdentite(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        JWTAuth::clearResolvedInstances();
    }

    private function avecJeton(string $jeton): void
    {
        $this->oublierIdentite();
        $this->withToken($jeton);
    }

    public function test_le_super_admin_voit_l_espace_d_un_admin_sans_pouvoir_le_modifier(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAsSuperAdmin();

        $res = $this->postJson("/api/v1/super-admin/users/{$admin->id}/consulter")
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.role', 'ADMIN')
            ->assertJsonPath('data.expireDans', 3600)
            ->assertJsonMissingPath('data.refreshToken');

        $this->avecJeton($res->json('data.accessToken'));
        $this->getJson('/api/v1/boutiques/me')->assertStatus(200)->assertJsonPath('data.id', $admin->boutique_id);
        $this->patchJson('/api/v1/boutiques/me', ['nom' => 'Piraté'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CONSULTATION_LECTURE_SEULE');
        $this->postJson('/api/v1/clients', ['nom' => 'Test'])->assertStatus(403);

        $this->assertNotSame('Piraté', $admin->boutique->fresh()->nom);
    }

    public function test_espace_caissier_d_une_boutique_et_boutique_sans_caissier(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAsSuperAdmin();

        $this->postJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}/consulter", ['role' => 'CAISSIER'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CONSULTATION_SANS_COMPTE');

        $caissier = User::factory()->caissier()->create(['boutique_id' => $admin->boutique_id]);
        $this->postJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}/consulter", ['role' => 'CAISSIER'])
            ->assertStatus(200)
            ->assertJsonPath('data.user.id', $caissier->id)
            ->assertJsonPath('data.user.role', 'CAISSIER');
    }

    public function test_une_boutique_suspendue_reste_consultable(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->boutique->update(['statut' => 'SUSPENDU', 'is_active' => false]);
        $this->actingAsSuperAdmin();

        $jeton = $this->postJson("/api/v1/super-admin/users/{$admin->id}/consulter")->assertStatus(200)->json('data.accessToken');
        $this->avecJeton($jeton);
        $this->getJson('/api/v1/stock')->assertStatus(200);
    }

    public function test_un_jeton_de_consultation_ne_se_rafraichit_pas(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAsSuperAdmin();
        $jeton = $this->postJson("/api/v1/super-admin/users/{$admin->id}/consulter")->json('data.accessToken');

        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $jeton])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'AUTH_INVALID_REFRESH');
    }

    public function test_le_rafraichissement_normal_fonctionne_toujours(): void
    {
        User::factory()->admin()->create(['email' => 'admin@test.ci', 'password_hash' => Hash::make('motdepasse1')]);
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.ci', 'password' => 'motdepasse1'])->assertStatus(200);

        $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $login->json('data.refreshToken')])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['accessToken']]);
    }

    public function test_seul_le_super_admin_ouvre_un_espace(): void
    {
        $admin = $this->actingAsAdmin();
        $caissier = User::factory()->caissier()->create(['boutique_id' => $admin->boutique_id]);

        $this->postJson("/api/v1/super-admin/users/{$caissier->id}/consulter")->assertStatus(403);
    }

    public function test_le_journal_trace_connexions_et_consultations_et_se_filtre(): void
    {
        User::factory()->admin()->create(['email' => 'admin@test.ci', 'password_hash' => Hash::make('motdepasse1')]);
        $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.ci', 'password' => 'motdepasse1'])->assertStatus(200);
        $admin = User::where('email', 'admin@test.ci')->firstOrFail();
        $autre = User::factory()->admin()->create();

        $this->oublierIdentite();
        $super = $this->actingAsSuperAdmin();
        $this->postJson("/api/v1/super-admin/users/{$admin->id}/consulter")->assertStatus(200);

        $this->assertTrue(AuditLog::where('action', 'CONNEXION')->where('user_id', $admin->id)->exists());

        // Filtré sur la boutique : la connexion de son admin et la consultation du Super Admin, pas l'autre boutique.
        // Les deux entrées peuvent tomber dans la même seconde : on ne suppose pas leur ordre.
        $journal = collect($this->getJson("/api/v1/super-admin/audit-logs?boutiqueId={$admin->boutique_id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->json('data'))->keyBy('action');
        $this->assertSame($super->email, $journal['CONSULTATION_ESPACE']['user']['email']);
        $this->assertSame($admin->boutique->nom, $journal['CONNEXION']['user']['boutique']['nom']);

        $this->getJson('/api/v1/super-admin/audit-logs?action=CONNEXION')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/super-admin/audit-logs?role=SUPER_ADMIN')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/super-admin/audit-logs?search=admin%40test.ci')->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/super-admin/audit-logs?boutiqueId={$autre->boutique_id}")->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/super-admin/audit-logs/actions')->assertJson(['data' => ['CONNEXION', 'CONSULTATION_ESPACE']]);
    }

    public function test_un_admin_ne_voit_que_le_journal_de_sa_boutique(): void
    {
        $voisin = User::factory()->admin()->create();
        AuditLog::record($voisin->id, 'SORTIE_DESTROY', 'Sortie', null, 'Suppression chez le voisin');

        $admin = $this->actingAsAdmin();
        AuditLog::record($admin->id, 'SORTIE_ANNULER', 'Sortie', null, 'Annulation chez moi');

        $this->getJson('/api/v1/audit-logs')->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'SORTIE_ANNULER');
        $this->assertInstanceOf(Boutique::class, $admin->boutique);
    }
}
