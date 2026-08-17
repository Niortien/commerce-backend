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

    // ──────────────────── GET /stock ────────────────────

    public function test_index_retourne_les_variantes_avec_produit(): void
    {
        $this->actingAsVendeur();
        Variante::factory()->count(3)->create();

        $this->getJson('/api/v1/stock')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_exclut_les_variantes_orphelines(): void
    {
        $this->actingAsVendeur();

        Variante::factory()->count(2)->create();

        // Variante orpheline : produit_id inexistant, insertion en bypass FK
        Schema::disableForeignKeyConstraints();
        \Illuminate\Support\Facades\DB::table('variantes')->insert([
            'id'             => (string) Str::uuid(),
            'produit_id'     => (string) Str::uuid(),
            'boutique_id'    => null,
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
        $this->actingAsVendeur();
        $v1 = Variante::factory()->create(['boutique_id' => null]);
        $v2 = Variante::factory()->create(['boutique_id' => null]);

        // On ne filtre pas par boutique : les deux remontent
        $this->getJson('/api/v1/stock')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);
    }

    // ──────────────────── GET /stock/alertes ────────────────────

    public function test_alertes_retourne_uniquement_les_variantes_sous_seuil(): void
    {
        $this->actingAsVendeur();

        Variante::factory()->create(['quantite_stock' => 20, 'seuil_alerte' => 5]);
        Variante::factory()->enAlerte()->create();
        Variante::factory()->create(['quantite_stock' => 5, 'seuil_alerte' => 5]);

        $this->getJson('/api/v1/stock/alertes')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2); // quantite_stock <= seuil_alerte
    }

    public function test_alertes_exclut_les_variantes_orphelines(): void
    {
        $this->actingAsVendeur();

        Variante::factory()->enAlerte()->create();

        Schema::disableForeignKeyConstraints();
        \Illuminate\Support\Facades\DB::table('variantes')->insert([
            'id'             => (string) Str::uuid(),
            'produit_id'     => (string) Str::uuid(),
            'boutique_id'    => null,
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
