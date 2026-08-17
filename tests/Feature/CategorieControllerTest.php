<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorieControllerTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────── GET /categories ────────────────────

    public function test_index_retourne_toutes_les_categories(): void
    {
        $this->actingAsAdmin();

        // Les migrations de données pré-insèrent des catégories canoniques.
        // On compte avant d'en ajouter 3 pour vérifier la différence.
        $before = Categorie::count();
        Categorie::factory()->count(3)->create();

        $this->getJson('/api/v1/categories')
            ->assertStatus(200)
            ->assertJsonPath('data', fn($d) => count($d) === $before + 3);
    }

    public function test_index_interdit_sans_authentification(): void
    {
        $this->getJson('/api/v1/categories')->assertStatus(401);
    }

    public function test_index_interdit_pour_vendeur(): void
    {
        $this->actingAsVendeur();
        $this->getJson('/api/v1/categories')->assertStatus(403);
    }

    // ──────────────────── POST /categories ────────────────────

    public function test_store_cree_une_categorie_avec_slug_auto(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/categories', [
            'nom'         => 'Chemise Bleue',
            'description' => 'Hauts',
        ])->assertStatus(201)
          ->assertJsonPath('data.slug', 'chemise-bleue');

        $this->assertDatabaseHas('categories', ['slug' => 'chemise-bleue']);
    }

    public function test_store_cree_une_categorie_avec_slug_explicite(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/categories', [
            'nom'         => 'Chemise Bleue',
            'slug'        => 'mon-slug-custom',
            'description' => 'Hauts',
        ])->assertStatus(201)
          ->assertJsonPath('data.slug', 'mon-slug-custom');
    }

    public function test_store_rejette_slug_explicite_duplique_en_422(): void
    {
        $this->actingAsAdmin();
        Categorie::factory()->create(['slug' => 'slug-existant']);

        $this->postJson('/api/v1/categories', [
            'nom'         => 'Nouvelle Cat',
            'slug'        => 'slug-existant',
            'description' => 'Hauts',
        ])->assertStatus(422);
    }

    public function test_store_rejette_slug_auto_duplique_en_409(): void
    {
        $this->actingAsAdmin();
        Categorie::factory()->create(['slug' => 'chemise-bleue']);

        $this->postJson('/api/v1/categories', [
            'nom'         => 'Chemise Bleue',
            'description' => 'Hauts',
        ])->assertStatus(409)
          ->assertJsonPath('error.code', 'CATEGORIE_SLUG_TAKEN');
    }

    public function test_store_rejette_description_invalide(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/categories', [
            'nom'         => 'Test',
            'description' => 'GroupeInexistant',
        ])->assertStatus(422);
    }

    public function test_store_interdit_sans_authentification(): void
    {
        $this->postJson('/api/v1/categories', ['nom' => 'Test', 'description' => 'Hauts'])
            ->assertStatus(401);
    }

    public function test_store_interdit_pour_vendeur(): void
    {
        $this->actingAsVendeur();
        $this->postJson('/api/v1/categories', ['nom' => 'Test', 'description' => 'Hauts'])
            ->assertStatus(403);
    }

    // ──────────────────── PATCH /categories/{id} ────────────────────

    public function test_update_modifie_les_champs(): void
    {
        $this->actingAsAdmin();
        $cat = Categorie::factory()->create(['nom' => 'Ancien Nom', 'slug' => 'ancien-nom']);

        $this->patchJson("/api/v1/categories/{$cat->id}", [
            'description' => 'Bas',
        ])->assertStatus(200)
          ->assertJsonPath('data.description', 'Bas');
    }

    public function test_update_regenere_slug_quand_nom_change(): void
    {
        $this->actingAsAdmin();
        $cat = Categorie::factory()->create(['nom' => 'Ancien', 'slug' => 'ancien']);

        $this->patchJson("/api/v1/categories/{$cat->id}", ['nom' => 'Nouveau Nom'])
            ->assertStatus(200)
            ->assertJsonPath('data.slug', 'nouveau-nom');
    }

    public function test_update_ne_regenere_pas_slug_si_inchange(): void
    {
        $this->actingAsAdmin();
        $cat = Categorie::factory()->create(['nom' => 'Meme Nom', 'slug' => 'meme-nom']);

        $this->patchJson("/api/v1/categories/{$cat->id}", ['nom' => 'Meme Nom'])
            ->assertStatus(200)
            ->assertJsonPath('data.slug', 'meme-nom');
    }

    public function test_update_rejette_collision_de_slug_auto(): void
    {
        $this->actingAsAdmin();
        Categorie::factory()->create(['slug' => 'nouveau-nom']);
        $cat = Categorie::factory()->create(['slug' => 'ancien-slug']);

        $this->patchJson("/api/v1/categories/{$cat->id}", ['nom' => 'Nouveau Nom'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CATEGORIE_SLUG_TAKEN');
    }

    public function test_update_retourne_404_si_introuvable(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/categories/id-inexistant', ['nom' => 'Test'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORIE_NOT_FOUND');
    }

    // ──────────────────── DELETE /categories/{id} ────────────────────

    public function test_destroy_supprime_une_categorie_vide(): void
    {
        $this->actingAsAdmin();
        $cat = Categorie::factory()->create();

        $this->deleteJson("/api/v1/categories/{$cat->id}")->assertStatus(200);
        $this->assertDatabaseMissing('categories', ['id' => $cat->id]);
    }

    public function test_destroy_bloque_si_produits_lies(): void
    {
        $this->actingAsAdmin();
        $cat = Categorie::factory()->create();
        Produit::factory()->create(['categorie_id' => $cat->id]);

        $this->deleteJson("/api/v1/categories/{$cat->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CATEGORIE_HAS_PRODUITS');

        $this->assertDatabaseHas('categories', ['id' => $cat->id]);
    }

    public function test_destroy_retourne_404_si_introuvable(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/categories/id-inexistant')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CATEGORIE_NOT_FOUND');
    }

    public function test_destroy_interdit_pour_vendeur(): void
    {
        $this->actingAsVendeur();
        $cat = Categorie::factory()->create();

        $this->deleteJson("/api/v1/categories/{$cat->id}")->assertStatus(403);
    }
}
