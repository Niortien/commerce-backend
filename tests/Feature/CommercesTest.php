<?php

namespace Tests\Feature;

use App\Models\CaisseSession;
use App\Models\Categorie;
use App\Models\Produit;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Types de commerce : restaurant (plats et fiches techniques, service en salle),
 * quincaillerie (quantités au mètre/kilo, devis), catégories libres et de départ.
 */
class CommercesTest extends TestCase
{
    use RefreshDatabase;

    private function ouvrirCaisse(User $user): void
    {
        CaisseSession::create([
            'user_id' => $user->id, 'boutique_id' => $user->boutique_id,
            'date_ouverture' => now(), 'montant_ouverture' => '0.00', 'statut' => 'OUVERTE',
        ]);
    }

    /** Produit + sa variante unique dans la boutique de l'utilisateur. */
    private function produit(User $user, array $produit, float $stock = 0): Variante
    {
        $categorie = Categorie::factory()->create(['boutique_id' => $user->boutique_id]);
        $p = Produit::factory()->create(array_merge([
            'boutique_id' => $user->boutique_id,
            'categorie_id' => $categorie->id,
        ], $produit));

        return Variante::create([
            'produit_id' => $p->id, 'boutique_id' => $user->boutique_id,
            'taille' => 'Unique', 'couleur' => '-', 'quantite_stock' => $stock, 'seuil_alerte' => 0,
        ]);
    }

    /** Garba poulet : 0,2 kg d'attiéké + 1 portion de poulet. */
    private function garba(User $admin): array
    {
        $this->metier($admin, 'RESTAURANT');
        $attieke = $this->produit($admin, ['nom' => 'Attiéké', 'unite' => 'KG', 'nature' => 'INGREDIENT'], 5);
        $poulet  = $this->produit($admin, ['nom' => 'Poulet', 'unite' => 'PORTION', 'nature' => 'INGREDIENT'], 10);
        $plat    = $this->produit($admin, ['nom' => 'Garba poulet', 'nature' => 'PLAT', 'prix_vente' => 1500]);

        $this->putJson("/api/v1/produits/{$plat->produit_id}/recette", [
            'lignes' => [
                ['varianteId' => $attieke->id, 'quantite' => 0.2],
                ['varianteId' => $poulet->id, 'quantite' => 1],
            ],
        ])->assertStatus(200)->assertJsonCount(2, 'data');

        return [$plat, $attieke, $poulet];
    }

    // ──────────────────── Restaurant ────────────────────

    public function test_vendre_un_plat_retire_ses_ingredients_et_pas_le_plat(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        [$plat, $attieke, $poulet] = $this->garba($admin);

        $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'modeService' => 'SUR_PLACE',
            'tableLabel' => 'Table 4',
            'lignes' => [['varianteId' => $plat->id, 'quantite' => 3, 'prixUnitaire' => 1500]],
        ])->assertStatus(201)
          ->assertJsonPath('data.modeService', 'SUR_PLACE')
          ->assertJsonPath('data.tableLabel', 'Table 4')
          ->assertJsonPath('data.totalMontant', '4500.00');

        $this->assertEqualsWithDelta(4.4, $attieke->fresh()->quantite_stock, 0.0001);
        $this->assertEqualsWithDelta(7, $poulet->fresh()->quantite_stock, 0.0001);
        $this->assertEqualsWithDelta(0, $plat->fresh()->quantite_stock, 0.0001);
    }

    public function test_ingredient_insuffisant_bloque_la_vente_du_plat(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        [$plat, $attieke] = $this->garba($admin);

        // 30 portions demandent 6 kg d'attiéké : il n'en reste que 5.
        $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'lignes' => [['varianteId' => $plat->id, 'quantite' => 30, 'prixUnitaire' => 1500]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'STOCK_INSUFFISANT');

        $this->assertEqualsWithDelta(5, $attieke->fresh()->quantite_stock, 0.0001);
    }

    public function test_annuler_la_vente_d_un_plat_remet_les_ingredients(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        [$plat, $attieke, $poulet] = $this->garba($admin);

        $sortieId = $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'lignes' => [['varianteId' => $plat->id, 'quantite' => 2, 'prixUnitaire' => 1500]],
        ])->assertStatus(201)->json('data.id');

        $this->patchJson("/api/v1/sorties/{$sortieId}/annuler")->assertStatus(200);

        $this->assertEqualsWithDelta(5, $attieke->fresh()->quantite_stock, 0.0001);
        $this->assertEqualsWithDelta(10, $poulet->fresh()->quantite_stock, 0.0001);
    }

    public function test_un_ingredient_ne_se_vend_pas_seul(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $sel = $this->produit($admin, ['nom' => 'Sel', 'unite' => 'KG', 'nature' => 'INGREDIENT'], 3);

        $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'lignes' => [['varianteId' => $sel->id, 'quantite' => 1, 'prixUnitaire' => 500]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'INGREDIENT_NON_VENDABLE');
    }

    public function test_un_plat_n_apparait_pas_dans_le_stock(): void
    {
        $admin = $this->actingAsAdmin();
        $this->garba($admin);

        // Seuls les deux ingrédients ont un stock ; le plat est préparé à la commande.
        $this->getJson('/api/v1/stock')->assertStatus(200)->assertJsonPath('meta.total', 2);
    }

    // ──────────────────── Quincaillerie ────────────────────

    public function test_vente_au_metre_avec_quantite_decimale(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $cable = $this->produit($admin, ['nom' => 'Câble 2,5 mm²', 'unite' => 'M'], 100);

        $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'lignes' => [['varianteId' => $cable->id, 'quantite' => 12.5, 'prixUnitaire' => 500]],
        ])->assertStatus(201)->assertJsonPath('data.totalMontant', '6250.00');

        $this->assertEqualsWithDelta(87.5, $cable->fresh()->quantite_stock, 0.0001);
    }

    public function test_quantite_decimale_refusee_pour_un_article_a_la_piece(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $marteau = $this->produit($admin, ['nom' => 'Marteau', 'unite' => 'PIECE'], 10);

        $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'lignes' => [['varianteId' => $marteau->id, 'quantite' => 1.5, 'prixUnitaire' => 3000]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'QUANTITE_ENTIERE');
    }

    public function test_un_devis_ne_touche_pas_au_stock_puis_devient_une_vente(): void
    {
        $admin = $this->metier($this->actingAsAdmin(), 'QUINCAILLERIE');
        $ciment = $this->produit($admin, ['nom' => 'Ciment 50 kg', 'unite' => 'SAC'], 40);

        $devisId = $this->postJson('/api/v1/devis', [
            'clientNom' => 'Entreprise Koné',
            'validiteJours' => 10,
            'lignes' => [['varianteId' => $ciment->id, 'quantite' => 12, 'prixUnitaire' => 5500]],
        ])->assertStatus(201)
          ->assertJsonPath('data.statut', 'EN_COURS')
          ->assertJsonPath('data.totalMontant', '66000.00')
          ->assertJsonPath('data.lignes.0.designation', 'Ciment 50 kg')
          ->json('data.id');

        $this->assertEqualsWithDelta(40, $ciment->fresh()->quantite_stock, 0.0001);

        // Sans caisse ouverte, pas de vente.
        $this->postJson("/api/v1/devis/{$devisId}/convertir")->assertStatus(409);

        $this->ouvrirCaisse($admin);
        $this->postJson("/api/v1/devis/{$devisId}/convertir")
            ->assertStatus(200)
            ->assertJsonPath('data.devis.statut', 'CONVERTI')
            ->assertJsonPath('data.sortie.totalMontant', '66000.00');

        $this->assertEqualsWithDelta(28, $ciment->fresh()->quantite_stock, 0.0001);

        $this->postJson("/api/v1/devis/{$devisId}/convertir")
            ->assertStatus(409)->assertJsonPath('error.code', 'DEVIS_DEJA_CONVERTI');
    }

    // ──────────────────── Catégories de départ ────────────────────

    public function test_un_restaurant_inscrit_recoit_des_categories_de_depart(): void
    {
        $response = $this->postJson('/api/v1/auth/inscription-boutique', [
            'typeCommerce' => 'RESTAURANT',
            'nomBoutique'  => 'Maquis Chez Tantie',
            'email'        => 'tantie@example.com',
            'password'     => 'motdepasse123',
        ])->assertStatus(201);

        $boutiqueId = $response->json('data.boutique.id');
        $this->assertSame('RESTAURANT', $response->json('data.boutique.typeCommerce'));
        $this->assertTrue(Categorie::where('boutique_id', $boutiqueId)->where('nom', 'Grillades')->where('description', 'Menu')->exists());
    }

    // ──────────────────── Accès par métier ────────────────────

    public function test_les_fonctions_d_un_autre_metier_sont_refusees_meme_par_url(): void
    {
        $admin = $this->metier($this->actingAsAdmin(), 'RESTAURANT');
        $plat = $this->produit($admin, ['nom' => 'Garba', 'nature' => 'PLAT']);

        foreach (['/api/v1/devis', '/api/v1/clients', '/api/v1/balles', '/api/v1/demarques'] as $url) {
            $this->getJson($url)->assertStatus(403)->assertJsonPath('error.code', 'FONCTION_AUTRE_METIER');
        }
        $this->postJson('/api/v1/balles', ['libelle' => 'X', 'coutAchat' => 1000])->assertStatus(403);
        $this->getJson("/api/v1/produits/{$plat->produit_id}/recette")->assertStatus(200);
    }

    public function test_une_boutique_de_vetements_n_a_pas_de_fiche_technique(): void
    {
        $admin = $this->metier($this->actingAsAdmin(), 'VETEMENTS');
        $produit = $this->produit($admin, ['nom' => 'Robe']);

        $this->getJson("/api/v1/produits/{$produit->produit_id}/recette")
            ->assertStatus(403)->assertJsonPath('error.code', 'FONCTION_AUTRE_METIER');
    }
}
