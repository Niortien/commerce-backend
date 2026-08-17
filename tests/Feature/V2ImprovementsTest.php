<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\CaisseSession;
use App\Models\Entree;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\Sortie;
use App\Models\Transaction;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2ImprovementsTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────── Fournisseur auto-lié sur une entrée ────────────────────

    public function test_store_entree_cree_automatiquement_le_fournisseur(): void
    {
        $this->actingAsVendeur();
        $variante = Variante::factory()->create();

        $this->postJson('/api/v1/entrees', [
            'fournisseur' => 'Textile Import SARL',
            'lignes' => [
                ['varianteId' => $variante->id, 'quantite' => 10, 'prixUnitaire' => 1000],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseCount('fournisseurs', 1);
        $fournisseur = Fournisseur::first();
        $this->assertSame('Textile Import SARL', $fournisseur->nom);
        $this->assertSame($fournisseur->id, Entree::first()->fournisseur_id);

        // Une deuxième entrée du même fournisseur ne duplique pas la fiche
        $this->postJson('/api/v1/entrees', [
            'fournisseur' => 'Textile Import SARL',
            'lignes' => [
                ['varianteId' => $variante->id, 'quantite' => 5, 'prixUnitaire' => 1000],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseCount('fournisseurs', 1);
    }

    // ──────────────────── Transfert de stock inter-boutiques ────────────────────

    public function test_transfert_deplace_le_stock_entre_boutiques(): void
    {
        $this->actingAsAdmin();
        $boutiqueA = Boutique::factory()->create();
        $boutiqueB = Boutique::factory()->create();
        $produit   = Produit::factory()->create();
        $source    = Variante::factory()->create([
            'produit_id' => $produit->id, 'boutique_id' => $boutiqueA->id,
            'taille' => 'M', 'couleur' => 'noir', 'quantite_stock' => 20,
        ]);

        $this->postJson('/api/v1/stock/transferts', [
            'varianteId'            => $source->id,
            'boutiqueDestinationId' => $boutiqueB->id,
            'quantite'              => 8,
        ])->assertStatus(201);

        $this->assertSame(12, $source->fresh()->quantite_stock);

        $destination = Variante::where('produit_id', $produit->id)
            ->where('boutique_id', $boutiqueB->id)
            ->first();
        $this->assertNotNull($destination);
        $this->assertSame(8, $destination->quantite_stock);
    }

    public function test_transfert_refuse_si_stock_source_insuffisant(): void
    {
        $this->actingAsAdmin();
        $boutiqueB = Boutique::factory()->create();
        $source    = Variante::factory()->create(['quantite_stock' => 3]);

        $this->postJson('/api/v1/stock/transferts', [
            'varianteId'            => $source->id,
            'boutiqueDestinationId' => $boutiqueB->id,
            'quantite'              => 10,
        ])->assertStatus(409);

        $this->assertSame(3, $source->fresh()->quantite_stock);
    }

    public function test_transfert_interdit_pour_vendeur(): void
    {
        $this->actingAsVendeur();
        $boutiqueB = Boutique::factory()->create();
        $source    = Variante::factory()->create(['quantite_stock' => 10]);

        $this->postJson('/api/v1/stock/transferts', [
            'varianteId'            => $source->id,
            'boutiqueDestinationId' => $boutiqueB->id,
            'quantite'              => 5,
        ])->assertStatus(403);
    }

    // ──────────────────── Écart de caisse à la fermeture ────────────────────

    public function test_close_session_calcule_ecart_de_caisse(): void
    {
        $admin   = $this->actingAsAdmin();
        $session = CaisseSession::create([
            'user_id' => $admin->id, 'boutique_id' => null,
            'date_ouverture' => now(), 'montant_ouverture' => '10000.00', 'statut' => 'OUVERTE',
        ]);
        Transaction::create([
            'session_id' => $session->id, 'montant' => '5000.00', 'mode_paiement' => 'CASH',
        ]);
        Transaction::create([
            'session_id' => $session->id, 'montant' => '3000.00', 'mode_paiement' => 'WAVE',
        ]);

        // Théorique = 10000 (ouverture) + 5000 (cash) = 15000. Le vendeur déclare 14500 → écart -500.
        $response = $this->postJson("/api/v1/caisse/sessions/{$session->id}/fermer", [
            'montantFermeture' => 14500,
        ])->assertStatus(200);

        $response->assertJsonPath('data.montantTheorique', '15000.00');
        $response->assertJsonPath('data.ecart', '-500.00');
    }

    // ──────────────────── Annulation/suppression de vente réservée à ADMIN ────────────────────

    public function test_vendeur_ne_peut_pas_supprimer_une_sortie(): void
    {
        $vendeur = $this->actingAsVendeur();
        $sortie = Sortie::create([
            'reference' => 'SRT-TEST0001', 'type' => 'VENTE', 'total_montant' => '1000.00', 'notes' => 'x',
            'user_id' => $vendeur->id,
        ]);

        $this->deleteJson("/api/v1/sorties/{$sortie->id}")->assertStatus(403);
        $this->patchJson("/api/v1/sorties/{$sortie->id}/annuler")->assertStatus(403);
    }

    public function test_admin_peut_annuler_une_sortie(): void
    {
        $admin = $this->actingAsAdmin();
        $sortie = Sortie::create([
            'reference' => 'SRT-TEST0002', 'type' => 'VENTE', 'total_montant' => '1000.00', 'notes' => 'x',
            'user_id' => $admin->id,
        ]);

        $this->patchJson("/api/v1/sorties/{$sortie->id}/annuler")->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Sortie', 'entity_id' => $sortie->id]);
    }

    // ──────────────────── Archivage boutique au lieu de suppression cascade ────────────────────

    public function test_boutique_avec_historique_est_archivee_pas_supprimee(): void
    {
        $admin = $this->actingAsAdmin();
        $boutique = Boutique::factory()->create();
        Entree::create([
            'reference' => 'ENT-TEST0001', 'fournisseur' => 'X', 'total_cout' => '0.00', 'boutique_id' => $boutique->id,
            'user_id' => $admin->id,
        ]);

        $this->deleteJson("/api/v1/boutiques/{$boutique->id}")->assertStatus(200);

        $this->assertDatabaseHas('boutiques', ['id' => $boutique->id, 'is_active' => false]);
    }

    public function test_boutique_sans_historique_est_supprimee(): void
    {
        $this->actingAsAdmin();
        $boutique = Boutique::factory()->create();

        $this->deleteJson("/api/v1/boutiques/{$boutique->id}")->assertStatus(200);

        $this->assertDatabaseMissing('boutiques', ['id' => $boutique->id]);
    }

    // ──────────────────── Réattribution de boutique (produit / variante) ────────────────────

    public function test_reassign_boutique_deplace_toutes_les_variantes_du_produit(): void
    {
        $this->actingAsAdmin();
        $mauvaiseBoutique = Boutique::factory()->create();
        $bonneBoutique    = Boutique::factory()->create();
        $produit = Produit::factory()->create();
        Variante::factory()->create(['produit_id' => $produit->id, 'boutique_id' => $mauvaiseBoutique->id, 'taille' => 'M', 'couleur' => 'noir']);
        Variante::factory()->create(['produit_id' => $produit->id, 'boutique_id' => $mauvaiseBoutique->id, 'taille' => 'L', 'couleur' => 'noir']);

        $response = $this->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'boutiqueId' => $bonneBoutique->id,
        ])->assertStatus(200);

        $response->assertJsonPath('data.movedCount', 2);
        $this->assertSame(0, Variante::where('produit_id', $produit->id)->where('boutique_id', $mauvaiseBoutique->id)->count());
        $this->assertSame(2, Variante::where('produit_id', $produit->id)->where('boutique_id', $bonneBoutique->id)->count());
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Produit', 'entity_id' => $produit->id]);
    }

    public function test_reassign_boutique_signale_les_conflits_sans_les_bloquer(): void
    {
        $this->actingAsAdmin();
        $boutiqueA = Boutique::factory()->create();
        $boutiqueB = Boutique::factory()->create();
        $produit = Produit::factory()->create();
        $aDeplacer = Variante::factory()->create(['produit_id' => $produit->id, 'boutique_id' => $boutiqueA->id, 'taille' => 'M', 'couleur' => 'noir']);
        // Une variante identique (M/noir) existe déjà dans la boutique destination → conflit
        Variante::factory()->create(['produit_id' => $produit->id, 'boutique_id' => $boutiqueB->id, 'taille' => 'M', 'couleur' => 'noir']);

        $response = $this->patchJson("/api/v1/produits/{$produit->id}/boutique", [
            'boutiqueId' => $boutiqueB->id,
        ])->assertStatus(200);

        $response->assertJsonPath('data.movedCount', 0);
        $response->assertJsonCount(1, 'data.conflicts');
        $this->assertSame($boutiqueA->id, $aDeplacer->fresh()->boutique_id);
    }

    public function test_variante_update_refuse_si_conflit_dans_boutique_destination(): void
    {
        $this->actingAsAdmin();
        $boutiqueA = Boutique::factory()->create();
        $boutiqueB = Boutique::factory()->create();
        $produit = Produit::factory()->create();
        $source = Variante::factory()->create(['produit_id' => $produit->id, 'boutique_id' => $boutiqueA->id, 'taille' => 'M', 'couleur' => 'noir']);
        Variante::factory()->create(['produit_id' => $produit->id, 'boutique_id' => $boutiqueB->id, 'taille' => 'M', 'couleur' => 'noir']);

        $this->patchJson("/api/v1/variantes/{$source->id}", ['boutiqueId' => $boutiqueB->id])
            ->assertStatus(409);
    }

    public function test_variante_update_reassigne_la_boutique_sans_conflit(): void
    {
        $this->actingAsAdmin();
        $boutique = Boutique::factory()->create();
        $variante = Variante::factory()->create(['boutique_id' => null]);

        $this->patchJson("/api/v1/variantes/{$variante->id}", ['boutiqueId' => $boutique->id])
            ->assertStatus(200)
            ->assertJsonPath('data.boutiqueId', $boutique->id);
    }
}
