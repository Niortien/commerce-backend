<?php

namespace Tests\Unit;

use App\Models\MouvementStock;
use App\Models\Variante;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockMovementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockMovementService();
    }

    // ──────────────────── Calcul du signe ────────────────────

    public function test_entree_augmente_le_stock(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 10]);

        $this->service->create($variante->id, 'ENTREE', 5, $user->id);

        $this->assertEquals(15, $variante->fresh()->quantite_stock);
    }

    public function test_retour_augmente_le_stock(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 10]);

        $this->service->create($variante->id, 'RETOUR', 3, $user->id);

        $this->assertEquals(13, $variante->fresh()->quantite_stock);
    }

    public function test_ajustement_augmente_le_stock(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 10]);

        $this->service->create($variante->id, 'AJUSTEMENT', 7, $user->id);

        $this->assertEquals(17, $variante->fresh()->quantite_stock);
    }

    public function test_sortie_diminue_le_stock(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 10]);

        $this->service->create($variante->id, 'SORTIE', 4, $user->id);

        $this->assertEquals(6, $variante->fresh()->quantite_stock);
    }

    public function test_sortie_est_le_seul_type_negatif(): void
    {
        $user = \App\Models\User::factory()->create();
        $v1 = Variante::factory()->create(['quantite_stock' => 10, 'taille' => 'S', 'couleur' => 'rouge']);
        $v2 = Variante::factory()->create(['quantite_stock' => 10, 'taille' => 'M', 'couleur' => 'rouge']);
        $v3 = Variante::factory()->create(['quantite_stock' => 10, 'taille' => 'L', 'couleur' => 'rouge']);

        // Tous les types non-positifs se réduisent à SORTIE
        $this->service->create($v1->id, 'ENTREE', 1, $user->id);
        $this->service->create($v2->id, 'RETOUR', 1, $user->id);
        $this->service->create($v3->id, 'SORTIE', 1, $user->id);

        $this->assertEquals(11, $v1->fresh()->quantite_stock);
        $this->assertEquals(11, $v2->fresh()->quantite_stock);
        $this->assertEquals(9,  $v3->fresh()->quantite_stock);
    }

    // ──────────────────── Stock insuffisant ────────────────────

    public function test_stock_insuffisant_leve_une_exception(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 3]);

        $this->expectException(\App\Exceptions\DomainException::class);

        $this->service->create($variante->id, 'SORTIE', 5, $user->id);
    }

    public function test_stock_insuffisant_ne_modifie_pas_le_stock(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 3]);

        try {
            $this->service->create($variante->id, 'SORTIE', 5, $user->id);
        } catch (\App\Exceptions\DomainException) {
        }

        $this->assertEquals(3, $variante->fresh()->quantite_stock);
        $this->assertEquals(0, MouvementStock::count());
    }

    public function test_sortie_exactement_egale_au_stock_est_autorisee(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 5]);

        $this->service->create($variante->id, 'SORTIE', 5, $user->id);

        $this->assertEquals(0, $variante->fresh()->quantite_stock);
    }

    // ──────────────────── Mouvement créé en base ────────────────────

    public function test_mouvement_est_enregistre_en_base(): void
    {
        $user = \App\Models\User::factory()->create();
        $variante = Variante::factory()->create(['quantite_stock' => 10]);

        $mouvement = $this->service->create($variante->id, 'ENTREE', 5, $user->id, 'réapprovisionnement');

        $this->assertInstanceOf(MouvementStock::class, $mouvement);
        $this->assertDatabaseHas('mouvement_stocks', [
            'variante_id' => $variante->id,
            'type'        => 'ENTREE',
            'quantite'    => 5,
            'motif'       => 'réapprovisionnement',
            'user_id'     => $user->id,
        ]);
    }
}
