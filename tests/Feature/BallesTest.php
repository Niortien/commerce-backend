<?php

namespace Tests\Feature;

use App\Models\Balle;
use App\Models\CaisseSession;
use App\Models\Categorie;
use App\Models\Entree;
use App\Models\Produit;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Friperie : balles achetées en bloc, déballées pièce par pièce ; chaque pièce est unique
 * et le coût de la balle se répartit sur ses pièces.
 */
class BallesTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $typeCommerce = 'FRIPERIE';

    private function ouvrirCaisse(User $user): void
    {
        CaisseSession::create([
            'user_id' => $user->id, 'boutique_id' => $user->boutique_id,
            'date_ouverture' => now(), 'montant_ouverture' => '0.00', 'statut' => 'OUVERTE',
        ]);
    }

    private function balle(array $champs = []): string
    {
        return $this->postJson('/api/v1/balles', array_merge([
            'libelle' => 'Jeans femme 45 kg',
            'fournisseur' => 'Grossiste Adjamé',
            'coutAchat' => 55000,
            'frais' => 5000,
        ], $champs))->assertStatus(201)->json('data.id');
    }

    /** @return array<int, array<string, mixed>> les pièces créées */
    private function deballer(User $user, string $balleId, array $prix): array
    {
        $rayon = Categorie::factory()->create(['boutique_id' => $user->boutique_id]);
        $pieces = array_map(fn($p, $i) => ['nom' => 'Pièce ' . ($i + 1), 'categorieId' => $rayon->id, 'prixVente' => $p], $prix, array_keys($prix));

        return $this->postJson("/api/v1/balles/{$balleId}/pieces", ['pieces' => $pieces])
            ->assertStatus(201)
            ->json('data');
    }

    private function vendre(string $varianteId, int|float $quantite, int $prix): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'lignes' => [['varianteId' => $varianteId, 'quantite' => $quantite, 'prixUnitaire' => $prix]],
        ]);
    }

    public function test_creer_une_balle_numerote_et_enregistre_la_depense(): void
    {
        $this->actingAsAdmin();

        $id = $this->balle();
        $this->getJson("/api/v1/balles/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.numero', 1)
            ->assertJsonPath('data.coutTotal', '60000.00')
            ->assertJsonPath('data.nbPieces', 0)
            ->assertJsonPath('data.statut', 'EN_COURS');

        $entree = Entree::firstOrFail();
        $this->assertSame('60000.00', (string) $entree->total_cout);
        $this->assertSame('Grossiste Adjamé', $entree->fournisseur);

        $this->postJson('/api/v1/balles', ['libelle' => 'Chemises', 'coutAchat' => 30000])
            ->assertStatus(201)->assertJsonPath('data.numero', 2);
    }

    public function test_deballer_met_chaque_piece_en_rayon_et_repartit_le_cout(): void
    {
        $admin = $this->actingAsAdmin();
        $id = $this->balle();

        $pieces = $this->deballer($admin, $id, [3000, 2500, 1500]);
        $this->assertCount(3, $pieces);
        $this->assertSame('B1-001', $pieces[0]['sku']);
        $this->assertTrue($pieces[0]['pieceUnique']);
        $this->assertSame(1, $pieces[0]['numeroPiece']);

        foreach (Produit::where('balle_id', $id)->get() as $p) {
            $this->assertSame('20000.00', (string) $p->prix_achat);
            $this->assertEqualsWithDelta(1, $p->variantes()->first()->quantite_stock, 0.0001);
        }

        // Une 4e pièce : le coût se répartit de nouveau, sur la pièce et sur la ligne d'entrée.
        $this->deballer($admin, $id, [2000]);
        $this->assertSame(['15000.00'], Produit::where('balle_id', $id)->pluck('prix_achat')->map(fn($v) => (string) $v)->unique()->values()->all());
        $this->assertSame(4, Entree::firstOrFail()->lignes()->where('prix_unitaire', 15000)->count());
        $this->assertSame('B1-004', Produit::where('balle_id', $id)->where('numero_piece', 4)->value('sku'));
    }

    public function test_une_piece_unique_ne_se_vend_qu_une_fois(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $piece = $this->deballer($admin, $this->balle(), [3000])[0];
        $varianteId = $piece['variantes'][0]['id'];

        $this->vendre($varianteId, 2, 3000)->assertStatus(422)->assertJsonPath('error.code', 'PIECE_UNIQUE_QUANTITE');
        $this->vendre($varianteId, 1, 3000)->assertStatus(201);
        $this->vendre($varianteId, 1, 3000)->assertStatus(409)->assertJsonPath('error.code', 'PIECE_DEJA_VENDUE');

        $this->getJson('/api/v1/produits?disponibilite=VENDUE')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/produits?disponibilite=EN_RAYON')->assertJsonCount(0, 'data');
    }

    public function test_annuler_la_vente_remet_la_piece_en_rayon(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $piece = $this->deballer($admin, $this->balle(), [3000])[0];
        $varianteId = $piece['variantes'][0]['id'];

        $sortieId = $this->vendre($varianteId, 1, 3000)->json('data.id');
        $this->patchJson("/api/v1/sorties/{$sortieId}/annuler")->assertStatus(200);

        $this->assertEqualsWithDelta(1, Variante::findOrFail($varianteId)->quantite_stock, 0.0001);
    }

    public function test_une_piece_unique_ne_peut_pas_etre_reapprovisionnee(): void
    {
        $admin = $this->actingAsAdmin();
        $piece = $this->deballer($admin, $this->balle(), [3000])[0];

        $this->postJson('/api/v1/entrees', [
            'fournisseur' => 'Test',
            'lignes' => [['varianteId' => $piece['variantes'][0]['id'], 'quantite' => 1, 'prixUnitaire' => 100]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'PIECE_DEJA_EN_RAYON');
    }

    public function test_le_bilan_compare_les_ventes_au_cout_de_la_balle(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $id = $this->balle(['coutAchat' => 9000, 'frais' => 1000]);
        $pieces = $this->deballer($admin, $id, [5000, 4000, 3000, 2000]);

        $this->vendre($pieces[0]['variantes'][0]['id'], 1, 5000)->assertStatus(201);
        // Vente avec remise : seule la somme réellement encaissée compte.
        $this->postJson('/api/v1/sorties', [
            'type' => 'VENTE',
            'remiseMontant' => 1000,
            'lignes' => [['varianteId' => $pieces[1]['variantes'][0]['id'], 'quantite' => 1, 'prixUnitaire' => 4000]],
        ])->assertStatus(201);

        $this->getJson("/api/v1/balles/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.nbPieces', 4)
            ->assertJsonPath('data.nbVendues', 2)
            ->assertJsonPath('data.nbEnRayon', 2)
            ->assertJsonPath('data.coutParPiece', '2500.00')
            ->assertJsonPath('data.recetteVentes', '8000.00')
            ->assertJsonPath('data.valeurEnRayon', '5000.00')
            ->assertJsonPath('data.marge', '-2000.00')
            ->assertJsonPath('data.tauxRembourse', 80)
            ->assertJsonCount(4, 'data.pieces');
    }

    public function test_retirer_une_piece_saisie_par_erreur_mais_pas_une_piece_vendue(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $id = $this->balle(['coutAchat' => 60000, 'frais' => 0]);
        $pieces = $this->deballer($admin, $id, [3000, 3000, 3000]);

        $this->vendre($pieces[0]['variantes'][0]['id'], 1, 3000)->assertStatus(201);
        $this->deleteJson("/api/v1/balles/{$id}/pieces/{$pieces[0]['id']}")
            ->assertStatus(409)->assertJsonPath('error.code', 'PIECE_DEJA_VENDUE');

        $this->deleteJson("/api/v1/balles/{$id}/pieces/{$pieces[2]['id']}")->assertStatus(200);
        $this->assertSame(2, Produit::where('balle_id', $id)->count());
        $this->assertSame('30000.00', (string) Produit::where('balle_id', $id)->value('prix_achat'));
    }

    public function test_un_deballage_termine_refuse_de_nouvelles_pieces(): void
    {
        $admin = $this->actingAsAdmin();
        $id = $this->balle();

        $this->patchJson("/api/v1/balles/{$id}/statut", ['statut' => 'TERMINEE'])->assertStatus(200);
        $rayon = Categorie::factory()->create(['boutique_id' => $admin->boutique_id]);
        $this->postJson("/api/v1/balles/{$id}/pieces", ['pieces' => [['nom' => 'Veste', 'categorieId' => $rayon->id, 'prixVente' => 3000]]])
            ->assertStatus(409)->assertJsonPath('error.code', 'BALLE_TERMINEE');
    }

    public function test_une_piece_vendue_n_est_pas_une_alerte_de_stock(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $piece = $this->deballer($admin, $this->balle(), [3000])[0];
        $this->vendre($piece['variantes'][0]['id'], 1, 3000)->assertStatus(201);

        $this->getJson('/api/v1/stock/alertes')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_les_listes_ne_sont_pas_gardees_en_cache_par_le_navigateur(): void
    {
        $this->actingAsAdmin();
        $this->balle();

        // Relue juste après une modification, une liste doit refléter cette modification.
        $this->assertStringContainsString('no-store', $this->getJson('/api/v1/balles')->headers->get('Cache-Control'));
    }

    public function test_une_balle_reste_privee_a_sa_boutique(): void
    {
        $autre = User::factory()->admin()->create();
        $balleAutre = Balle::create([
            'boutique_id' => $autre->boutique_id, 'numero' => 1, 'libelle' => 'Balle voisine',
            'cout_achat' => 10000, 'frais' => 0, 'date_achat' => now()->toDateString(), 'user_id' => $autre->id,
        ]);

        $this->actingAsAdmin();
        $this->getJson("/api/v1/balles/{$balleAutre->id}")->assertStatus(404);
        $this->getJson('/api/v1/balles')->assertJsonCount(0, 'data');
    }

    public function test_seule_une_balle_vide_se_supprime(): void
    {
        $admin = $this->actingAsAdmin();
        $pleine = $this->balle();
        $this->deballer($admin, $pleine, [3000]);
        $vide = $this->balle(['libelle' => 'Erreur de saisie']);

        $this->deleteJson("/api/v1/balles/{$pleine}")->assertStatus(409)->assertJsonPath('error.code', 'BALLE_NON_VIDE');
        $this->deleteJson("/api/v1/balles/{$vide}")->assertStatus(200);
        $this->assertSame(1, Entree::count());
    }

    // ──────────────────── Tri par choix ────────────────────

    public function test_le_bilan_se_detaille_par_choix(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $id = $this->balle(['prixChoix1' => 3000, 'prixChoix2' => 1500]);
        $this->getJson("/api/v1/balles/{$id}")->assertJsonPath('data.prixChoix1', '3000.00')->assertJsonPath('data.prixChoix3', null);

        $rayon = Categorie::factory()->create(['boutique_id' => $admin->boutique_id]);
        $pieces = $this->postJson("/api/v1/balles/{$id}/pieces", ['pieces' => [
            ['nom' => 'Veste', 'categorieId' => $rayon->id, 'prixVente' => 3000, 'choix' => 1],
            ['nom' => 'Chemise', 'categorieId' => $rayon->id, 'prixVente' => 1500, 'choix' => 2],
            ['nom' => 'Polo', 'categorieId' => $rayon->id, 'prixVente' => 1500, 'choix' => 2],
        ]])->assertStatus(201)->assertJsonPath('data.0.choix', 1)->json('data');

        $this->vendre($pieces[1]['variantes'][0]['id'], 1, 1500)->assertStatus(201);

        $this->getJson("/api/v1/balles/{$id}")
            ->assertJsonCount(2, 'data.parChoix')
            ->assertJsonPath('data.parChoix.0.choix', 1)
            ->assertJsonPath('data.parChoix.0.nbPieces', 1)
            ->assertJsonPath('data.parChoix.1.choix', 2)
            ->assertJsonPath('data.parChoix.1.nbPieces', 2)
            ->assertJsonPath('data.parChoix.1.nbVendues', 1)
            ->assertJsonPath('data.parChoix.1.recette', '1500.00');

        $this->postJson("/api/v1/balles/{$id}/pieces", ['pieces' => [
            ['nom' => 'Short', 'categorieId' => $rayon->id, 'prixVente' => 500, 'choix' => 4],
        ]])->assertStatus(422);
    }

    // ──────────────────── Démarque ────────────────────

    /** Pièces mises en rayon il y a $jours jours. */
    private function vieillir(array $pieces, int $jours): void
    {
        Produit::whereIn('id', array_column($pieces, 'id'))->update(['created_at' => now()->subDays($jours)]);
    }

    public function test_seules_les_pieces_anciennes_et_en_rayon_sont_a_demarquer(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $pieces = $this->deballer($admin, $this->balle(), [3000, 2500, 2000]);
        $this->vieillir([$pieces[0], $pieces[1]], 40);
        $this->vendre($pieces[1]['variantes'][0]['id'], 1, 2500)->assertStatus(201);

        $this->getJson('/api/v1/demarques?joursMin=30')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pieces[0]['id'])
            ->assertJsonPath('data.0.joursEnRayon', 40)
            ->assertJsonPath('data.0.balle.numero', 1);

        $this->getJson('/api/v1/demarques?joursMin=0')->assertJsonCount(2, 'data');
    }

    public function test_demarquer_baisse_le_prix_arrondi_et_garde_le_prix_d_origine(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $pieces = $this->deballer($admin, $this->balle(), [3000, 2500, 1500]);
        $this->vieillir($pieces, 40);
        $this->vendre($pieces[2]['variantes'][0]['id'], 1, 1500)->assertStatus(201);

        // 2500 x 0,7 = 1750 ; 3000 x 0,7 = 2100 ; la pièce vendue n'est pas touchée.
        $this->postJson('/api/v1/demarques', [
            'produitIds' => array_column($pieces, 'id'),
            'mode' => 'POURCENTAGE',
            'valeur' => 30,
        ])->assertStatus(200)
          ->assertJsonPath('data.nbDemarquees', 2)
          ->assertJsonPath('data.nbIgnorees', 1)
          ->assertJsonPath('data.totalAvant', '5500.00')
          ->assertJsonPath('data.totalApres', '3850.00');

        $veste = Produit::findOrFail($pieces[0]['id']);
        $this->assertSame('2100.00', (string) $veste->prix_vente);
        $this->assertSame('3000.00', (string) $veste->prix_initial);
        $this->assertSame(1, $veste->nb_demarques);

        // Démarquée aujourd'hui : elle repart pour un tour avant d'être de nouveau proposée.
        $this->getJson('/api/v1/demarques?joursMin=30')->assertJsonCount(0, 'data');

        // Deuxième démarque à prix fixe (arrondi aux 50 F) : le prix d'origine ne bouge pas.
        $this->postJson('/api/v1/demarques', ['produitIds' => [$veste->id], 'mode' => 'PRIX', 'valeur' => 1020])
            ->assertJsonPath('data.nbDemarquees', 1);
        $veste->refresh();
        $this->assertSame('1000.00', (string) $veste->prix_vente);
        $this->assertSame('3000.00', (string) $veste->prix_initial);
        $this->assertSame(2, $veste->nb_demarques);
    }

    public function test_une_demarque_ne_remonte_jamais_le_prix(): void
    {
        $admin = $this->actingAsAdmin();
        $piece = $this->deballer($admin, $this->balle(), [1000])[0];

        $this->postJson('/api/v1/demarques', ['produitIds' => [$piece['id']], 'mode' => 'PRIX', 'valeur' => 1500])
            ->assertJsonPath('data.nbDemarquees', 0)
            ->assertJsonPath('data.nbIgnorees', 1);
        $this->postJson('/api/v1/demarques', ['produitIds' => [$piece['id']], 'mode' => 'POURCENTAGE', 'valeur' => 100])
            ->assertStatus(422)->assertJsonPath('error.code', 'DEMARQUE_INVALIDE');
        $this->assertSame('1000.00', (string) Produit::findOrFail($piece['id'])->prix_vente);
    }

    public function test_seul_l_admin_demarque(): void
    {
        // Un seul utilisateur par test : le jeton du premier resterait en cache.
        $caissier = $this->actingAsCaissier();
        $rayon = Categorie::factory()->create(['boutique_id' => $caissier->boutique_id]);
        $piece = Produit::factory()->create([
            'boutique_id' => $caissier->boutique_id, 'categorie_id' => $rayon->id, 'prix_vente' => 1000, 'piece_unique' => true,
        ]);
        Variante::create([
            'produit_id' => $piece->id, 'boutique_id' => $caissier->boutique_id,
            'taille' => 'Unique', 'couleur' => '-', 'quantite_stock' => 1, 'seuil_alerte' => 0,
        ]);

        $this->getJson('/api/v1/demarques?joursMin=0')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/demarques', ['produitIds' => [$piece->id], 'mode' => 'POURCENTAGE', 'valeur' => 20])
            ->assertStatus(403);
    }

    public function test_demarquer_ignore_les_pieces_d_une_autre_boutique(): void
    {
        $this->actingAsAdmin();
        $autre = User::factory()->admin()->create();
        $autreRayon = Categorie::factory()->create(['boutique_id' => $autre->boutique_id]);
        $etrangere = Produit::factory()->create(['boutique_id' => $autre->boutique_id, 'categorie_id' => $autreRayon->id, 'prix_vente' => 2000, 'piece_unique' => true]);

        $this->postJson('/api/v1/demarques', ['produitIds' => [$etrangere->id], 'mode' => 'POURCENTAGE', 'valeur' => 50])
            ->assertStatus(200)->assertJsonPath('data.nbDemarquees', 0);
        $this->assertSame('2000.00', (string) $etrangere->fresh()->prix_vente);
    }

    // ──────────────────── Tas à prix unique ────────────────────

    public function test_un_tas_compte_pour_tous_ses_articles_dans_le_cout_et_le_bilan(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $id = $this->balle(['coutAchat' => 60000, 'frais' => 0]);
        $rayon = Categorie::factory()->create(['boutique_id' => $admin->boutique_id]);

        $pieces = $this->postJson("/api/v1/balles/{$id}/pieces", ['pieces' => [
            ['nom' => 'Veste', 'categorieId' => $rayon->id, 'prixVente' => 5000],
            ['nom' => 'Robe', 'categorieId' => $rayon->id, 'prixVente' => 4000],
            ['nom' => 'Tas tee-shirts', 'categorieId' => $rayon->id, 'prixVente' => 500, 'quantite' => 10],
        ]])->assertStatus(201)->json('data');

        $tas = $pieces[2];
        $this->assertFalse($tas['pieceUnique']);
        $this->assertEqualsWithDelta(10, $tas['variantes'][0]['quantiteStock'], 0.001);

        // 12 articles : 60 000 / 12 = 5 000 par article, tas compris.
        $this->assertSame(['5000.00'], Produit::where('balle_id', $id)->pluck('prix_achat')->map(fn($v) => (string) $v)->unique()->values()->all());

        // Un tas se vend par plusieurs exemplaires.
        $this->vendre($tas['variantes'][0]['id'], 3, 500)->assertStatus(201);

        $this->getJson("/api/v1/balles/{$id}")
            ->assertJsonPath('data.nbPieces', 12)
            ->assertJsonPath('data.nbTas', 1)
            ->assertJsonPath('data.nbEnRayon', 9)
            ->assertJsonPath('data.nbVendues', 3)
            ->assertJsonPath('data.coutParPiece', '5000.00')
            ->assertJsonPath('data.recetteVentes', '1500.00')
            ->assertJsonPath('data.valeurEnRayon', '12500.00');

        // Un tas entamé ne se retire plus de la balle.
        $this->deleteJson("/api/v1/balles/{$id}/pieces/{$tas['id']}")->assertStatus(409);
    }

    public function test_un_tas_qui_traine_se_demarque_aussi(): void
    {
        $admin = $this->actingAsAdmin();
        $id = $this->balle();
        $rayon = Categorie::factory()->create(['boutique_id' => $admin->boutique_id]);
        $tas = $this->postJson("/api/v1/balles/{$id}/pieces", ['pieces' => [
            ['nom' => 'Tas chaussettes', 'categorieId' => $rayon->id, 'prixVente' => 300, 'quantite' => 20],
        ]])->json('data.0');
        $this->vieillir([$tas], 40);

        $this->getJson('/api/v1/demarques?joursMin=30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tas['id']);
    }
}
