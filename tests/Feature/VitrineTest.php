<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\Produit;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vitrine en ligne : page publique d'une boutique, qui ne doit jamais laisser
 * sortir le prix d'achat ni les quantités exactes en stock.
 */
class VitrineTest extends TestCase
{
    use RefreshDatabase;

    private function produit(Boutique $boutique, array $attrs, array $stocks): Produit
    {
        $categorie = Categorie::factory()->create(['boutique_id' => $boutique->id]);
        $p = Produit::factory()->create(array_merge([
            'boutique_id' => $boutique->id,
            'categorie_id' => $categorie->id,
            'prix_vente' => 15000,
            'prix_achat' => 6000,
            'is_actif' => true,
        ], $attrs));
        foreach ($stocks as $taille => $stock) {
            Variante::create([
                'produit_id' => $p->id, 'boutique_id' => $boutique->id,
                'taille' => $taille, 'couleur' => 'Noir', 'quantite_stock' => $stock, 'seuil_alerte' => 0,
            ]);
        }
        return $p;
    }

    public function test_une_vitrine_non_publiee_est_introuvable(): void
    {
        $boutique = Boutique::factory()->create(['vitrine_active' => false]);

        $this->getJson("/api/v1/vitrine/{$boutique->slug}")->assertStatus(404);
    }

    public function test_une_boutique_suspendue_n_a_plus_de_vitrine(): void
    {
        $boutique = Boutique::factory()->create(['vitrine_active' => true, 'statut' => 'SUSPENDU', 'is_active' => false]);

        $this->getJson("/api/v1/vitrine/{$boutique->slug}")->assertStatus(404);
    }

    public function test_la_vitrine_montre_le_stock_disponible_sans_les_donnees_privees(): void
    {
        $boutique = Boutique::factory()->create(['vitrine_active' => true, 'nom' => 'Dri Valé']);
        $tshirt = $this->produit($boutique, ['nom' => 'T-shirt'], ['M' => 10, 'L' => 2, 'XL' => 0]);
        $this->produit($boutique, ['nom' => 'Épuisé'], ['M' => 0]);
        $this->produit($boutique, ['nom' => 'Masqué', 'is_actif' => false], ['M' => 5]);
        $this->produit($boutique, ['nom' => 'Attiéké', 'nature' => 'INGREDIENT'], ['Unique' => 8]);
        $this->produit($boutique, ['nom' => 'Garba', 'nature' => 'PLAT'], ['Unique' => 0]);

        $res = $this->getJson("/api/v1/vitrine/{$boutique->slug}")->assertStatus(200);

        $res->assertJsonPath('data.boutique.nom', 'Dri Valé');
        $noms = collect($res->json('data.produits'))->pluck('nom')->sort()->values()->all();
        $this->assertSame(['Garba', 'T-shirt'], $noms);

        $t = collect($res->json('data.produits'))->firstWhere('id', $tshirt->id);
        $tailles = collect($t['variantes'])->keyBy('taille');
        $this->assertEqualsCanonicalizing(['M', 'L'], $tailles->keys()->all());
        $this->assertFalse($tailles['M']['dernieresPieces']);
        $this->assertTrue($tailles['L']['dernieresPieces']);

        $json = $res->getContent();
        $this->assertStringNotContainsString('prixAchat', $json);
        $this->assertStringNotContainsString('6000', $json);
        $this->assertStringNotContainsString('quantiteStock', $json);
    }

    public function test_le_prix_promo_n_apparait_que_pendant_la_promotion(): void
    {
        $boutique = Boutique::factory()->create(['vitrine_active' => true]);
        $this->produit($boutique, ['nom' => 'En cours', 'en_promo' => true, 'prix_promo' => 12000, 'date_fin_promo' => now()->addDay()], ['M' => 4]);
        $this->produit($boutique, ['nom' => 'Finie', 'en_promo' => true, 'prix_promo' => 12000, 'date_fin_promo' => now()->subDay()], ['M' => 4]);

        $produits = collect($this->getJson("/api/v1/vitrine/{$boutique->slug}")->json('data.produits'))->keyBy('nom');

        $this->assertEquals(12000, $produits['En cours']['prixPromo']);
        $this->assertNull($produits['Finie']['prixPromo']);
    }

    public function test_le_gerant_publie_sa_vitrine_depuis_ma_boutique(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patchJson('/api/v1/boutiques/me', ['vitrineActive' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.vitrineActive', true);

        $this->assertTrue($admin->boutique->fresh()->vitrine_active);
    }

    public function test_le_catalogue_public_cache_le_prix_d_achat_aux_visiteurs(): void
    {
        $boutique = Boutique::factory()->create();
        $p = $this->produit($boutique, ['nom' => 'Polo'], ['M' => 3]);

        $liste = $this->getJson("/api/v1/produits?boutiqueSlug={$boutique->slug}")->assertStatus(200);
        $this->assertArrayNotHasKey('prixAchat', $liste->json('data.0'));
        $this->assertArrayNotHasKey('prixAchat', $this->getJson("/api/v1/produits/{$p->id}")->json('data'));
    }

    public function test_la_boutique_voit_toujours_son_prix_d_achat(): void
    {
        $admin = $this->actingAsAdmin();
        $p = $this->produit($admin->boutique, ['nom' => 'Polo'], ['M' => 3]);

        $this->getJson("/api/v1/produits?boutiqueSlug={$admin->boutique->slug}")
            ->assertStatus(200)
            ->assertJsonPath('data.0.prixAchat', '6000.00');
        $this->getJson("/api/v1/produits/{$p->id}")->assertJsonPath('data.prixAchat', '6000.00');
    }
}
