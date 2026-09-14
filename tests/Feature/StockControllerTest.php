<?php

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockControllerTest extends TestCase
{
    use RefreshDatabase;

    // Crée une variante rattachée à un produit de la même boutique (invariant respecté).
    private function varianteDansBoutique(string $boutiqueId, array $overrides = []): Variante
    {
        $produit = Produit::factory()->create(['boutique_id' => $boutiqueId]);

        return Variante::factory()->create(array_merge([
            'produit_id'  => $produit->id,
            'boutique_id' => $boutiqueId,
        ], $overrides));
    }

    // ──────────────────── GET /stock ────────────────────

    public function test_index_retourne_les_variantes_avec_produit(): void
    {
        $vendeur = $this->actingAsVendeur();
        for ($i = 0; $i < 3; $i++) {
            $this->varianteDansBoutique($vendeur->boutique_id);
        }

        $this->getJson('/api/v1/stock')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_exclut_les_variantes_orphelines(): void
    {
        $vendeur = $this->actingAsVendeur();

        for ($i = 0; $i < 2; $i++) {
            $this->varianteDansBoutique($vendeur->boutique_id);
        }

        // Variante orpheline : produit_id inexistant, insertion en bypass FK
        Schema::disableForeignKeyConstraints();
        \Illuminate\Support\Facades\DB::table('variantes')->insert([
            'id'             => (string) Str::uuid(),
            'produit_id'     => (string) Str::uuid(),
            'boutique_id'    => $vendeur->boutique_id,
            'taille'         => 'M',
            'couleur'        => 'rouge',
            'quantite_stock' => 10,
            'seuil_alerte'   => 5,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $this->getJson('/api/v1/stock')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_index_filtre_par_boutique(): void
    {
        $vendeur = $this->actingAsVendeur();
        $v1 = $this->varianteDansBoutique($vendeur->boutique_id);
        // Variante d'une autre boutique : ne doit pas remonter (isolation tenant).
        $v2 = Variante::factory()->create();

        $this->getJson('/api/v1/stock')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    // ──────────────────── GET /stock/alertes ────────────────────

    public function test_alertes_retourne_uniquement_les_variantes_sous_seuil(): void
    {
        $vendeur = $this->actingAsVendeur();

        $this->varianteDansBoutique($vendeur->boutique_id, ['quantite_stock' => 20, 'seuil_alerte' => 5]);
        $this->varianteDansBoutique($vendeur->boutique_id, ['quantite_stock' => 2, 'seuil_alerte' => 5]);
        $this->varianteDansBoutique($vendeur->boutique_id, ['quantite_stock' => 5, 'seuil_alerte' => 5]);

        $this->getJson('/api/v1/stock/alertes')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2); // quantite_stock <= seuil_alerte
    }

    public function test_alertes_exclut_les_variantes_orphelines(): void
    {
        $vendeur = $this->actingAsVendeur();

        $this->varianteDansBoutique($vendeur->boutique_id, ['quantite_stock' => 2, 'seuil_alerte' => 5]);

        Schema::disableForeignKeyConstraints();
        \Illuminate\Support\Facades\DB::table('variantes')->insert([
            'id'             => (string) Str::uuid(),
            'produit_id'     => (string) Str::uuid(),
            'boutique_id'    => $vendeur->boutique_id,
            'taille'         => 'S',
            'couleur'        => 'bleu',
            'quantite_stock' => 1,
            'seuil_alerte'   => 5,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        Schema::enableForeignKeyConstraints();

        $this->getJson('/api/v1/stock/alertes')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_stock_interdit_sans_authentification(): void
    {
        $this->getJson('/api/v1/stock')->assertStatus(401);
    }
}
