<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\CaisseSession;
use App\Models\Entree;
use App\Models\Fournisseur;
use App\Models\Sortie;
use App\Models\Transaction;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NOTE : le transfert de stock inter-boutiques et la réattribution de
 * boutique d'un produit/variante ont été retirés lors du passage au modèle
 * multi-tenant (chaque boutique est un locataire isolé — on ne déplace plus
 * du stock d'un tenant à un autre). Les tests correspondants ont été
 * supprimés avec les endpoints.
 */
class V2ImprovementsTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────── Fournisseur auto-lié sur une entrée ────────────────────

    public function test_store_entree_cree_automatiquement_le_fournisseur(): void
    {
        $admin = $this->actingAsAdmin();
        $variante = Variante::factory()->create(['boutique_id' => $admin->boutique_id]);

        $this->postJson('/api/v1/entrees', [
            'fournisseur' => 'Textile Import SARL',
            'lignes' => [
                ['varianteId' => $variante->id, 'quantite' => 10, 'prixUnitaire' => 1000],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseCount('fournisseurs', 1);
        $fournisseur = Fournisseur::first();
        $this->assertSame('Textile Import SARL', $fournisseur->nom);
        $this->assertSame($admin->boutique_id, $fournisseur->boutique_id);
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

    // ──────────────────── Écart de caisse à la fermeture ────────────────────

    public function test_close_session_calcule_ecart_de_caisse(): void
    {
        $admin   = $this->actingAsAdmin();
        $session = CaisseSession::create([
            'user_id' => $admin->id, 'boutique_id' => $admin->boutique_id,
            'date_ouverture' => now(), 'montant_ouverture' => '10000.00', 'statut' => 'OUVERTE',
        ]);
        Transaction::create([
            'session_id' => $session->id, 'montant' => '5000.00', 'mode_paiement' => 'CASH',
        ]);
        Transaction::create([
            'session_id' => $session->id, 'montant' => '3000.00', 'mode_paiement' => 'WAVE',
        ]);

        // Théorique = 10000 (ouverture) + 5000 (cash) = 15000. L'admin déclare 14500 → écart -500.
        $response = $this->postJson("/api/v1/caisse/sessions/{$session->id}/fermer", [
            'montantFermeture' => 14500,
        ])->assertStatus(200);

        $response->assertJsonPath('data.montantTheorique', '15000.00');
        $response->assertJsonPath('data.ecart', '-500.00');
    }

    // ──────────────────── Annulation/suppression de vente réservée à ADMIN ────────────────────

    public function test_caissier_ne_peut_pas_supprimer_une_sortie(): void
    {
        $caissier = $this->actingAsCaissier();
        $sortie = Sortie::create([
            'reference' => 'SRT-TEST0001', 'type' => 'VENTE', 'total_montant' => '1000.00', 'notes' => 'x',
            'user_id' => $caissier->id, 'boutique_id' => $caissier->boutique_id,
        ]);

        $this->deleteJson("/api/v1/sorties/{$sortie->id}")->assertStatus(403);
        $this->patchJson("/api/v1/sorties/{$sortie->id}/annuler")->assertStatus(403);
    }

    public function test_admin_peut_annuler_une_sortie(): void
    {
        $admin = $this->actingAsAdmin();
        $sortie = Sortie::create([
            'reference' => 'SRT-TEST0002', 'type' => 'VENTE', 'total_montant' => '1000.00', 'notes' => 'x',
            'user_id' => $admin->id, 'boutique_id' => $admin->boutique_id,
        ]);

        $this->patchJson("/api/v1/sorties/{$sortie->id}/annuler")->assertStatus(200);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Sortie', 'entity_id' => $sortie->id]);
    }

    // ──────────────────── Archivage boutique au lieu de suppression cascade ────────────────────

    public function test_boutique_avec_historique_est_archivee_pas_supprimee(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();
        $boutique = Boutique::factory()->create();
        Entree::create([
            'reference' => 'ENT-TEST0001', 'fournisseur' => 'X', 'total_cout' => '0.00', 'boutique_id' => $boutique->id,
            'user_id' => $superAdmin->id,
        ]);

        $this->deleteJson("/api/v1/super-admin/boutiques/{$boutique->id}")->assertStatus(200);

        $this->assertDatabaseHas('boutiques', ['id' => $boutique->id, 'is_active' => false, 'statut' => 'ARCHIVE']);
    }

    public function test_boutique_sans_historique_est_supprimee(): void
    {
        $this->actingAsSuperAdmin();
        $boutique = Boutique::factory()->create();

        $this->deleteJson("/api/v1/super-admin/boutiques/{$boutique->id}")->assertStatus(200);

        $this->assertDatabaseMissing('boutiques', ['id' => $boutique->id]);
    }
}
