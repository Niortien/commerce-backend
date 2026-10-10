<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\CaisseSession;
use App\Models\Categorie;
use App\Models\Entree;
use App\Models\Produit;
use App\Models\Sortie;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variante;
use App\Services\CategoriesDeDepart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Saisie rapide (codes-barres, import d'un catalogue), ventes faites hors connexion
 * et catégories de départ pour chaque type de commerce.
 */
class SaisieRapideEtHorsLigneTest extends TestCase
{
    use RefreshDatabase;

    private function ouvrirCaisse(User $user, $ouverture = null): CaisseSession
    {
        return CaisseSession::create([
            'user_id' => $user->id, 'boutique_id' => $user->boutique_id,
            'date_ouverture' => $ouverture ?? now(), 'montant_ouverture' => '0.00', 'statut' => 'OUVERTE',
        ]);
    }

    private function article(User $user, string $nom = 'Coca 33 cl', float $stock = 20, ?string $code = null): Variante
    {
        $categorie = Categorie::factory()->create(['boutique_id' => $user->boutique_id]);
        $p = Produit::factory()->create([
            'boutique_id' => $user->boutique_id, 'categorie_id' => $categorie->id, 'nom' => $nom, 'prix_vente' => 500,
        ]);
        return Variante::create([
            'produit_id' => $p->id, 'boutique_id' => $user->boutique_id, 'taille' => 'Unique', 'couleur' => '-',
            'code_barre' => $code, 'quantite_stock' => $stock, 'seuil_alerte' => 0,
        ]);
    }

    // ──────────────────── Codes-barres ────────────────────

    public function test_on_retrouve_un_article_par_son_code_barres(): void
    {
        $admin = $this->actingAsAdmin();
        $coca = $this->article($admin, 'Coca 33 cl', 20, '5449000000996');

        $this->getJson('/api/v1/variantes/code/5449000000996')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $coca->id)
            ->assertJsonPath('data.produit.nom', 'Coca 33 cl');

        $this->getJson('/api/v1/variantes/code/0000')->assertStatus(404)->assertJsonPath('error.code', 'CODE_BARRE_INCONNU');
    }

    public function test_un_code_barres_ne_sert_qu_une_fois_par_boutique(): void
    {
        $admin = $this->actingAsAdmin();
        $coca = $this->article($admin, 'Coca 33 cl', 20, '123456');
        $fanta = $this->article($admin, 'Fanta 33 cl');

        $this->patchJson("/api/v1/variantes/{$fanta->id}", ['codeBarre' => '123456'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CODE_BARRE_PRIS');
        $this->patchJson("/api/v1/variantes/{$coca->id}", ['codeBarre' => ' 123456 '])->assertStatus(200);
        $this->patchJson("/api/v1/variantes/{$fanta->id}", ['codeBarre' => '654321'])->assertStatus(200);
        $this->assertSame('654321', $fanta->fresh()->code_barre);

        // Une autre boutique peut avoir le même code (produit industriel identique).
        $autre = User::factory()->admin()->create();
        $this->article($autre, 'Coca 33 cl', 5, '123456');
        $this->assertSame(2, Variante::where('code_barre', '123456')->count());
    }

    public function test_le_code_barres_se_donne_a_la_creation_du_produit(): void
    {
        $admin = $this->actingAsAdmin();
        $categorie = Categorie::factory()->create(['boutique_id' => $admin->boutique_id]);

        $this->postJson('/api/v1/produits', [
            'nom' => 'Lait Bonnet Rouge', 'categorieId' => $categorie->id, 'prixVente' => 600, 'prixAchat' => 450,
            'variantes' => [['taille' => 'Unique', 'couleur' => '-', 'quantiteStock' => 10, 'codeBarre' => '3033710065967']],
        ])->assertStatus(201);
        $this->assertTrue(Variante::where('code_barre', '3033710065967')->exists());

        $this->postJson('/api/v1/produits', [
            'nom' => 'Autre lait', 'categorieId' => $categorie->id, 'prixVente' => 600, 'prixAchat' => 450,
            'variantes' => [['taille' => 'Unique', 'couleur' => '-', 'quantiteStock' => 10, 'codeBarre' => '3033710065967']],
        ])->assertStatus(409)->assertJsonPath('error.code', 'CODE_BARRE_PRIS');
    }

    // ──────────────────── Import du catalogue ────────────────────

    public function test_l_import_cree_produits_variantes_categories_et_stock_de_depart(): void
    {
        $admin = $this->actingAsAdmin();

        $lignes = [
            ['nom' => 'Riz parfumé 5 kg', 'categorie' => 'Épicerie', 'prixVente' => '4500', 'prixAchat' => '3800', 'quantite' => '12', 'unite' => 'sac', 'codeBarre' => '111'],
            ['nom' => 'T-shirt Basic', 'categorie' => 'Hauts', 'prixVente' => 5000, 'prixAchat' => 2500, 'quantite' => 3, 'taille' => 'M', 'couleur' => 'Noir'],
            ['nom' => 't-shirt basic', 'categorie' => 'Hauts', 'prixVente' => 5000, 'prixAchat' => 2500, 'quantite' => 2, 'taille' => 'L', 'couleur' => 'Noir'],
            ['nom' => '', 'categorie' => 'Hauts', 'prixVente' => 5000],
            ['nom' => 'Sans prix', 'categorie' => 'Hauts', 'prixVente' => 'abc'],
            ['nom' => 'Huile', 'categorie' => 'Épicerie', 'prixVente' => 1500, 'quantite' => '2.5', 'unite' => 'PIECE'],
        ];

        // La simulation vérifie tout sans rien enregistrer.
        $this->postJson('/api/v1/produits/import', ['simulation' => true, 'lignes' => $lignes])
            ->assertStatus(200)
            ->assertJsonPath('data.produitsCrees', 2)
            ->assertJsonPath('data.variantesCreees', 3)
            ->assertJsonCount(3, 'data.erreurs');
        $this->assertSame(0, Produit::count());
        $this->assertSame(0, Entree::count());

        $rapport = $this->postJson('/api/v1/produits/import', ['lignes' => $lignes])->assertStatus(201)->json('data');
        $this->assertSame(2, $rapport['produitsCrees']);
        $this->assertSame(3, $rapport['variantesCreees']);
        $this->assertSame(3, $rapport['stocksAjoutes']);
        $this->assertSame([4, 5, 6], array_column($rapport['erreurs'], 'ligne'));

        $riz = Variante::where('code_barre', '111')->with('produit')->firstOrFail();
        $this->assertSame('SAC', $riz->produit->unite);
        $this->assertEqualsWithDelta(12, $riz->quantite_stock, 0.001);
        $this->assertSame(2, Produit::where('nom', 'T-shirt Basic')->firstOrFail()->variantes()->count());
        $this->assertTrue(Categorie::where('boutique_id', $admin->boutique_id)->where('nom', 'Épicerie')->exists());

        $entree = Entree::where('reference', $rapport['entreeReference'])->firstOrFail();
        $this->assertSame('Import du catalogue', $entree->fournisseur);
        $this->assertSame('58100.00', (string) $entree->total_cout); // 12×3800 + 5×2500

        // Réimporter le même fichier ajoute du stock sans recréer d'articles.
        $this->postJson('/api/v1/produits/import', ['lignes' => array_slice($lignes, 0, 1)])
            ->assertStatus(201)->assertJsonPath('data.produitsCrees', 0)->assertJsonPath('data.variantesCreees', 0);
        $this->assertEqualsWithDelta(24, $riz->fresh()->quantite_stock, 0.001);
    }

    public function test_l_import_refuse_un_code_barres_deja_pris(): void
    {
        $admin = $this->actingAsAdmin();
        $this->article($admin, 'Coca 33 cl', 20, '999');

        $this->postJson('/api/v1/produits/import', ['lignes' => [
            ['nom' => 'Fanta', 'categorie' => 'Boissons', 'prixVente' => 500, 'codeBarre' => '999'],
        ]])->assertStatus(201)->assertJsonPath('data.produitsCrees', 0)->assertJsonPath('data.erreurs.0.ligne', 1);
    }

    public function test_seul_l_admin_importe_un_catalogue(): void
    {
        $this->actingAsCaissier();
        $this->postJson('/api/v1/produits/import', ['lignes' => [['nom' => 'X', 'categorie' => 'Y', 'prixVente' => 1]]])
            ->assertStatus(403);
        $this->assertSame(0, Produit::count());
    }

    // ──────────────────── Ventes hors connexion ────────────────────

    public function test_une_vente_hors_ligne_envoyee_deux_fois_n_est_comptee_qu_une_fois(): void
    {
        $caissier = $this->actingAsCaissier();
        $session = $this->ouvrirCaisse($caissier, now()->subHours(3));
        $coca = $this->article($caissier);
        $venduLe = now()->subHour()->startOfMinute();

        $corps = [
            'clientRef' => (string) Str::uuid(),
            'venduLe' => $venduLe->toIso8601String(),
            'modePaiement' => 'CASH',
            'lignes' => [['varianteId' => $coca->id, 'quantite' => 2, 'prixUnitaire' => 500]],
        ];

        $id = $this->postJson('/api/v1/sorties/hors-ligne', $corps)
            ->assertStatus(201)->assertJsonPath('data.totalMontant', '1000.00')->json('data.id');
        $this->postJson('/api/v1/sorties/hors-ligne', $corps)->assertStatus(200)->assertJsonPath('data.id', $id);

        $this->assertSame(1, Sortie::count());
        $this->assertEqualsWithDelta(18, $coca->fresh()->quantite_stock, 0.001);
        $tx = Transaction::firstOrFail();
        $this->assertSame($session->id, $tx->session_id);
        $this->assertSame('1000.00', (string) $tx->montant);
        $this->assertTrue($venduLe->equalTo(Sortie::firstOrFail()->created_at));
    }

    public function test_une_vente_arrivee_sans_son_paiement_est_completee_au_renvoi(): void
    {
        $caissier = $this->actingAsCaissier();
        $this->ouvrirCaisse($caissier);
        $coca = $this->article($caissier);
        $lignes = [['varianteId' => $coca->id, 'quantite' => 1, 'prixUnitaire' => 500]];

        // Envoi normal arrivé au serveur, mais la réponse s'est perdue : le paiement n'a pas suivi.
        $id = $this->postJson('/api/v1/sorties', ['type' => 'VENTE', 'clientRef' => 'ref-perdue', 'lignes' => $lignes])
            ->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/sorties', ['type' => 'VENTE', 'clientRef' => 'ref-perdue', 'lignes' => $lignes])
            ->assertStatus(200)->assertJsonPath('data.id', $id);

        $this->postJson('/api/v1/sorties/hors-ligne', [
            'clientRef' => 'ref-perdue', 'venduLe' => now()->toIso8601String(), 'modePaiement' => 'CASH', 'lignes' => $lignes,
        ])->assertStatus(200)->assertJsonPath('data.id', $id);

        $this->assertSame(1, Sortie::count());
        $this->assertSame(1, Transaction::where('sortie_id', $id)->count());
        $this->assertEqualsWithDelta(19, $coca->fresh()->quantite_stock, 0.001);
    }

    public function test_la_vente_va_dans_la_session_ouverte_au_moment_de_la_vente(): void
    {
        $caissier = $this->actingAsCaissier();
        $ancienne = CaisseSession::create([
            'user_id' => $caissier->id, 'boutique_id' => $caissier->boutique_id,
            'date_ouverture' => now()->subDay()->setTime(8, 0), 'date_fermeture' => now()->subDay()->setTime(20, 0),
            'montant_ouverture' => '0.00', 'statut' => 'FERMEE',
        ]);
        $this->ouvrirCaisse($caissier);
        $coca = $this->article($caissier);

        $this->postJson('/api/v1/sorties/hors-ligne', [
            'clientRef' => 'hl-1', 'venduLe' => now()->subDay()->setTime(15, 0)->toIso8601String(), 'modePaiement' => 'WAVE',
            'lignes' => [['varianteId' => $coca->id, 'quantite' => 1, 'prixUnitaire' => 500]],
        ])->assertStatus(201);

        $this->assertSame($ancienne->id, Transaction::firstOrFail()->session_id);
    }

    public function test_sans_aucune_session_la_vente_hors_ligne_est_refusee_clairement(): void
    {
        $caissier = $this->actingAsCaissier();
        $coca = $this->article($caissier);

        $this->postJson('/api/v1/sorties/hors-ligne', [
            'clientRef' => 'hl-2', 'venduLe' => now()->toIso8601String(), 'modePaiement' => 'CASH',
            'lignes' => [['varianteId' => $coca->id, 'quantite' => 1, 'prixUnitaire' => 500]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_SESSION');
        $this->assertEqualsWithDelta(20, $coca->fresh()->quantite_stock, 0.001);
    }

    // ──────────────────── Catégories de départ ────────────────────

    public function test_chaque_type_de_commerce_recoit_des_categories_de_depart(): void
    {
        foreach (Boutique::TYPES_COMMERCE as $type) {
            $boutique = Boutique::factory()->create(['type_commerce' => $type]);
            app(CategoriesDeDepart::class)->creer($boutique);
            $this->assertGreaterThan(0, Categorie::where('boutique_id', $boutique->id)->count(), "Aucune catégorie pour {$type}");
        }
    }
}
