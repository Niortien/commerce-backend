<?php

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Les actions du Super Admin (statut, abonnements, suppression) doivent être persistées ET
 * produire leur effet réel côté boutique (caisse, stock), sans retour en arrière silencieux.
 */
class SuperAdminAbonnementsEtSuppressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ces tests passent d'un compte à l'autre (Super Admin, admin de boutique) : dans un test,
     * l'application vit d'une requête à l'autre et le garde JWT garderait sinon l'ancien compte.
     */
    private function oublierIdentite(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        JWTAuth::clearResolvedInstances();
    }

    protected function actingAsAdmin(?User $user = null): User
    {
        $this->oublierIdentite();
        return parent::actingAsAdmin($user);
    }

    protected function actingAsSuperAdmin(?User $user = null): User
    {
        $this->oublierIdentite();
        return parent::actingAsSuperAdmin($user);
    }

    private function boutiqueAvecAdmin(string $statut = 'ACTIF'): User
    {
        $admin = User::factory()->admin()->create();
        $admin->boutique->update(['statut' => $statut, 'is_active' => in_array($statut, Boutique::STATUTS_ACTIFS, true)]);
        return $admin->fresh();
    }

    /** Remplace les abonnements de la boutique par un seul, terminé depuis une heure. */
    private function abonnementTermine(Boutique $boutique, string $plan = 'MENSUEL'): Abonnement
    {
        Abonnement::where('boutique_id', $boutique->id)->delete();
        return Abonnement::create([
            'boutique_id' => $boutique->id, 'plan' => $plan, 'statut' => 'ACTIF',
            'date_debut' => now()->subDays(30), 'date_fin' => now()->subHour(),
        ]);
    }

    private function ouvrirCaisse(User $user)
    {
        $this->actingAsAdmin($user);
        return $this->postJson('/api/v1/caisse/sessions/ouvrir', ['montantOuverture' => 0]);
    }

    // ──────────────────── Expiration et renouvellement ────────────────────

    public function test_la_fin_d_essai_bloque_la_caisse_avec_un_message_clair(): void
    {
        $admin = $this->boutiqueAvecAdmin('ESSAI');
        $this->abonnementTermine($admin->boutique, 'ESSAI');

        $this->ouvrirCaisse($admin)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'BOUTIQUE_ACCESS_SUSPENDED')
            ->assertJsonPath('error.details.cause', 'ABONNEMENT_EXPIRE');

        $this->assertStringContainsString("période d'essai", $this->ouvrirCaisse($admin)->json('error.message'));
        $this->assertSame('SUSPENDU', $admin->boutique->fresh()->statut);
    }

    public function test_le_renouvellement_rouvre_une_boutique_suspendue_a_l_expiration(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $this->abonnementTermine($admin->boutique);
        $this->ouvrirCaisse($admin)->assertStatus(403);

        $this->actingAsSuperAdmin();
        $this->postJson('/api/v1/super-admin/abonnements', [
            'boutiqueId' => $admin->boutique_id, 'plan' => 'MENSUEL', 'dateFin' => now()->addDays(30)->toDateString(),
        ])->assertStatus(201)->assertJsonPath('data.boutique.statut', 'ACTIF');

        // Persisté : relu par la liste du Super Admin…
        $liste = collect($this->getJson('/api/v1/super-admin/boutiques')->json('data'))->firstWhere('id', $admin->boutique_id);
        $this->assertSame('ACTIF', $liste['statut']);
        // … et réel côté boutique : la caisse s'ouvre.
        $this->ouvrirCaisse($admin)->assertStatus(201);
        $this->assertSame('ACTIF', $admin->boutique->fresh()->statut);
    }

    public function test_reactiver_sans_abonnement_en_cours_est_refuse_au_lieu_d_etre_annule_en_silence(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $this->abonnementTermine($admin->boutique);
        $admin->boutique->synchroniserStatutAbonnement();

        $this->actingAsSuperAdmin();
        $this->patchJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}/statut", ['statut' => 'ACTIF'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ABONNEMENT_REQUIS');

        $this->assertSame('SUSPENDU', $admin->boutique->fresh()->statut);
    }

    public function test_prolonger_la_date_de_fin_rouvre_l_acces(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $abonnement = $this->abonnementTermine($admin->boutique);
        $this->ouvrirCaisse($admin)->assertStatus(403);

        $this->actingAsSuperAdmin();
        $nouvelleFin = now()->addDays(15)->toDateString();
        $this->patchJson("/api/v1/super-admin/abonnements/{$abonnement->id}", ['dateFin' => $nouvelleFin])
            ->assertStatus(200)
            ->assertJsonPath('data.boutique.statut', 'ACTIF');

        // Une date sans heure vaut jusqu'à la fin de ce jour-là.
        $this->assertSame($nouvelleFin . ' 23:59:59', $abonnement->fresh()->date_fin->format('Y-m-d H:i:s'));
        $this->ouvrirCaisse($admin)->assertStatus(201);
    }

    public function test_annuler_l_abonnement_en_cours_bloque_la_boutique(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $abonnement = $admin->boutique->abonnementActif();

        $this->actingAsSuperAdmin();
        $this->patchJson("/api/v1/super-admin/abonnements/{$abonnement->id}", ['statut' => 'ANNULE'])
            ->assertStatus(200)
            ->assertJsonPath('data.boutique.statut', 'SUSPENDU');

        $this->ouvrirCaisse($admin)->assertStatus(403)->assertJsonPath('error.code', 'BOUTIQUE_ACCESS_SUSPENDED');
    }

    public function test_changer_de_plan_garde_un_seul_abonnement_courant_et_le_tarif_du_plan(): void
    {
        $admin = $this->boutiqueAvecAdmin('ESSAI');

        $this->actingAsSuperAdmin();
        $res = $this->postJson('/api/v1/super-admin/abonnements', [
            'boutiqueId' => $admin->boutique_id, 'plan' => 'MENSUEL', 'dateFin' => now()->addDays(30)->toDateString(),
        ])->assertStatus(201);

        $this->assertEquals(5000, (float) $res->json('data.montant'));
        $this->assertSame('ACTIF', $res->json('data.boutique.statut'));
        $this->assertSame(1, Abonnement::where('boutique_id', $admin->boutique_id)->where('statut', 'ACTIF')->count());
    }

    public function test_une_date_de_fin_avant_le_debut_est_refusee(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $this->actingAsSuperAdmin();

        $this->postJson('/api/v1/super-admin/abonnements', [
            'boutiqueId' => $admin->boutique_id, 'plan' => 'MENSUEL', 'dateFin' => now()->subDays(2)->toDateString(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'ABONNEMENT_PERIODE_INVALIDE');
    }

    // ──────────────────── Suspension manuelle ────────────────────

    public function test_la_suspension_manuelle_bloque_avec_son_motif_et_n_est_levee_que_sur_demande(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');

        $this->actingAsSuperAdmin();
        $this->patchJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}/statut", ['statut' => 'SUSPENDU', 'motif' => 'Impayé de novembre'])
            ->assertStatus(200)->assertJsonPath('data.statut', 'SUSPENDU');

        $refus = $this->ouvrirCaisse($admin)->assertStatus(403)->assertJsonPath('error.details.cause', 'MANUELLE');
        $this->assertStringContainsString('Impayé de novembre', $refus->json('error.message'));

        // La boutique voit pourquoi, même suspendue.
        $this->getJson('/api/v1/boutiques/me')->assertStatus(200)
            ->assertJsonPath('data.statut', 'SUSPENDU')
            ->assertJsonPath('data.accesAutorise', false)
            ->assertJsonPath('data.suspensionMotif', 'Impayé de novembre');

        // Renouveler sans « reactiver » ne lève pas une suspension décidée par le Super Admin…
        $this->actingAsSuperAdmin();
        $this->postJson('/api/v1/super-admin/abonnements', [
            'boutiqueId' => $admin->boutique_id, 'plan' => 'MENSUEL', 'dateFin' => now()->addDays(30)->toDateString(),
        ])->assertStatus(201)->assertJsonPath('data.boutique.statut', 'SUSPENDU');

        // … « reactiver » la lève, et la caisse s'ouvre.
        $this->postJson('/api/v1/super-admin/abonnements', [
            'boutiqueId' => $admin->boutique_id, 'plan' => 'MENSUEL', 'dateFin' => now()->addDays(30)->toDateString(), 'reactiver' => true,
        ])->assertStatus(201)->assertJsonPath('data.boutique.statut', 'ACTIF');
        $this->ouvrirCaisse($admin)->assertStatus(201);
    }

    public function test_reactiver_une_suspension_manuelle_avec_abonnement_en_cours(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $this->actingAsSuperAdmin();
        $this->patchJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}/statut", ['statut' => 'SUSPENDU'])->assertStatus(200);
        $this->ouvrirCaisse($admin)->assertStatus(403);

        $this->actingAsSuperAdmin();
        $this->patchJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}/statut", ['statut' => 'ACTIF'])->assertStatus(200);

        $this->ouvrirCaisse($admin)->assertStatus(201);
        $boutique = $admin->boutique->fresh();
        $this->assertSame('ACTIF', $boutique->statut);
        $this->assertNull($boutique->suspension_cause);
    }

    // ──────────────────── Contrôle d'accès ────────────────────

    public function test_un_admin_de_boutique_ne_peut_pas_faire_les_actions_du_super_admin(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $autre = $this->boutiqueAvecAdmin('ACTIF');
        $this->actingAsAdmin($admin);

        $this->patchJson("/api/v1/super-admin/boutiques/{$autre->boutique_id}/statut", ['statut' => 'SUSPENDU'])->assertStatus(403);
        $this->postJson('/api/v1/super-admin/abonnements', [
            'boutiqueId' => $admin->boutique_id, 'plan' => 'ANNUEL', 'dateFin' => now()->addYear()->toDateString(),
        ])->assertStatus(403);
        $this->deleteJson("/api/v1/super-admin/boutiques/{$autre->boutique_id}", ['confirmation' => $autre->boutique->nom])->assertStatus(403);

        $this->assertSame('ACTIF', $autre->boutique->fresh()->statut);
        $this->assertDatabaseHas('boutiques', ['id' => $autre->boutique_id]);
    }

    public function test_un_admin_n_agit_que_sur_sa_propre_boutique(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $autre = $this->boutiqueAvecAdmin('ACTIF');
        $categorie = Categorie::factory()->create(['boutique_id' => $autre->boutique_id]);

        $this->actingAsAdmin($autre);
        $produitAutre = $this->postJson('/api/v1/produits', [
            'nom' => 'Produit de l\'autre', 'categorieId' => $categorie->id, 'prixVente' => 1000, 'prixAchat' => 500,
            'variantes' => [['taille' => 'U', 'couleur' => 'Noir', 'quantiteStock' => 3]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAsAdmin($admin);
        $this->patchJson("/api/v1/produits/{$produitAutre}", ['nom' => 'Piraté'])->assertStatus(404);
        $this->patchJson('/api/v1/boutiques/me', ['nom' => 'Ma boutique renommée'])->assertStatus(200);

        $this->assertSame('Ma boutique renommée', $admin->boutique->fresh()->nom);
        $this->assertNotSame('Ma boutique renommée', $autre->boutique->fresh()->nom);
        $this->assertDatabaseHas('produits', ['id' => $produitAutre, 'nom' => 'Produit de l\'autre']);
    }

    // ──────────────────── Suppression définitive ────────────────────

    public function test_la_suppression_exige_le_nom_de_la_boutique(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $this->actingAsSuperAdmin();

        $this->deleteJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}")
            ->assertStatus(422)->assertJsonPath('error.code', 'CONFIRMATION_SUPPRESSION_INVALIDE');
        $this->deleteJson("/api/v1/super-admin/boutiques/{$admin->boutique_id}", ['confirmation' => 'autre nom'])
            ->assertStatus(422);

        $this->assertDatabaseHas('boutiques', ['id' => $admin->boutique_id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_supprimer_une_boutique_avec_produits_ventes_caisse_et_comptes_ne_laisse_aucune_donnee(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $boutiqueId = $admin->boutique_id;
        $caissier = User::factory()->caissier()->create(['boutique_id' => $boutiqueId]);
        $categorie = Categorie::factory()->create(['boutique_id' => $boutiqueId]);
        $temoin = $this->boutiqueAvecAdmin('ACTIF');

        // Données créées par les vrais parcours de la boutique : produit, entrée, caisse, vente encaissée.
        $this->actingAsAdmin($admin);
        $produit = $this->postJson('/api/v1/produits', [
            'nom' => 'Hoodie test', 'categorieId' => $categorie->id, 'prixVente' => 15000, 'prixAchat' => 8000,
            'variantes' => [['taille' => 'L', 'couleur' => 'Noir', 'quantiteStock' => 10]],
        ])->assertStatus(201);
        $varianteId = $produit->json('data.variantes.0.id');
        $this->postJson('/api/v1/entrees', [
            'fournisseur' => 'Grossiste test', 'lignes' => [['varianteId' => $varianteId, 'quantite' => 5, 'prixUnitaire' => 8000]],
        ])->assertStatus(201);
        $this->postJson('/api/v1/caisse/sessions/ouvrir', ['montantOuverture' => 0])->assertStatus(201);
        $vente = $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE', 'lignes' => [['varianteId' => $varianteId, 'quantite' => 2, 'prixUnitaire' => 15000]],
        ])->assertStatus(201);
        $this->postJson('/api/v1/caisse/transactions', [
            'montant' => 30000, 'modePaiement' => 'CASH', 'sortieId' => $vente->json('data.id'),
        ])->assertStatus(201);

        $this->actingAsSuperAdmin();
        $nom = $admin->boutique->nom;
        $res = $this->deleteJson("/api/v1/super-admin/boutiques/{$boutiqueId}", ['confirmation' => $nom])
            ->assertStatus(200)
            ->assertJsonPath('data.nom', $nom);
        $this->assertSame(1, $res->json('data.supprime.produits'));
        $this->assertSame(2, $res->json('data.supprime.utilisateurs'));

        // Plus aucune ligne rattachée à la boutique, ses comptes compris.
        foreach (['abonnements', 'balles', 'caisse_sessions', 'categories', 'clients', 'devis', 'entrees',
                  'fournisseurs', 'operations_credit', 'produits', 'sorties', 'users', 'variantes'] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertSame(0, DB::table($table)->where('boutique_id', $boutiqueId)->count(), "Reste dans {$table}");
            }
        }
        $this->assertDatabaseMissing('boutiques', ['id' => $boutiqueId]);
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $caissier->id]);
        $this->assertSame(0, DB::table('ligne_sorties')->where('variante_id', $varianteId)->count());
        $this->assertSame(0, DB::table('ligne_entrees')->where('variante_id', $varianteId)->count());
        $this->assertSame(0, DB::table('mouvement_stocks')->where('variante_id', $varianteId)->count());
        $this->assertSame(0, DB::table('transactions')->where('sortie_id', $vente->json('data.id'))->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'BOUTIQUE_DESTROY', 'entity_id' => $boutiqueId]);

        // Les autres boutiques ne sont pas touchées.
        $this->assertDatabaseHas('boutiques', ['id' => $temoin->boutique_id]);
        $this->assertDatabaseHas('users', ['id' => $temoin->id]);

        // L'email de l'ancien admin est de nouveau libre.
        $this->postJson('/api/v1/auth/inscription-boutique', [
            'nomBoutique' => 'Nouvelle vie', 'email' => $admin->email, 'password' => 'motdepasse123',
        ])->assertStatus(201);
    }

    // ──────────────────── Erreurs explicites (jamais un faux « erreur serveur ») ────────────────────

    public function test_trop_de_connexions_renvoie_429_et_non_500(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@example.com', 'password' => 'mauvais-mdp']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@example.com', 'password' => 'mauvais-mdp'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_supprimer_un_caissier_qui_a_vendu_est_refuse_avec_409(): void
    {
        $admin = $this->boutiqueAvecAdmin('ACTIF');
        $caissier = User::factory()->caissier()->create(['boutique_id' => $admin->boutique_id]);
        $this->actingAsAdmin($caissier);
        $this->postJson('/api/v1/caisse/sessions/ouvrir', ['montantOuverture' => 0])->assertStatus(201);

        $this->actingAsAdmin($admin);
        $this->deleteJson("/api/v1/users/{$caissier->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'USER_HAS_HISTORY');
        $this->assertDatabaseHas('users', ['id' => $caissier->id]);

        $nouveau = User::factory()->caissier()->create(['boutique_id' => $admin->boutique_id]);
        $this->deleteJson("/api/v1/users/{$nouveau->id}")->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $nouveau->id]);
    }

    // ──────────────────── Tarifs ────────────────────

    public function test_le_mensuel_coute_5000_et_les_anciens_abonnements_gardent_leur_tarif(): void
    {
        $this->assertSame(5000, Abonnement::TARIFS_FCFA['MENSUEL']);
        $this->assertSame(13500, Abonnement::TARIFS_FCFA['TRIMESTRIEL']);
        $this->assertSame(48000, Abonnement::TARIFS_FCFA['ANNUEL']);

        $this->actingAsSuperAdmin();
        $res = $this->postJson('/api/v1/super-admin/boutiques', [
            'nom' => 'Boutique tarif', 'adminEmail' => 'tarif@example.com', 'adminPassword' => 'motdepasse123', 'plan' => 'MENSUEL',
        ])->assertStatus(201);
        $this->assertEquals(5000, (float) $res->json('data.abonnement.montant'));
    }
}
