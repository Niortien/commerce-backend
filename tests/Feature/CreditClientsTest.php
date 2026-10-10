<?php

namespace Tests\Feature;

use App\Models\CaisseSession;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\OperationCredit;
use App\Models\Produit;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Crédit clients (quincaillerie) : vente à crédit avec ou sans acompte, plafond, règlements,
 * retards (les règlements soldent les ventes les plus anciennes) et annulation.
 */
class CreditClientsTest extends TestCase
{
    use RefreshDatabase;

    private function ouvrirCaisse(User $user): void
    {
        CaisseSession::create([
            'user_id' => $user->id, 'boutique_id' => $user->boutique_id,
            'date_ouverture' => now(), 'montant_ouverture' => '0.00', 'statut' => 'OUVERTE',
        ]);
    }

    private function ciment(User $user, float $stock = 100): Variante
    {
        $categorie = Categorie::factory()->create(['boutique_id' => $user->boutique_id]);
        $p = Produit::factory()->create([
            'boutique_id' => $user->boutique_id, 'categorie_id' => $categorie->id,
            'nom' => 'Ciment 50 kg', 'unite' => 'SAC', 'prix_vente' => 5000,
        ]);
        return Variante::create([
            'produit_id' => $p->id, 'boutique_id' => $user->boutique_id,
            'taille' => 'Unique', 'couleur' => '-', 'quantite_stock' => $stock, 'seuil_alerte' => 0,
        ]);
    }

    private function client(array $champs = []): string
    {
        return $this->postJson('/api/v1/clients', array_merge(['nom' => 'Koné BTP', 'telephone' => '0700000000'], $champs))
            ->assertStatus(201)->json('data.id');
    }

    private function venteACredit(Variante $ciment, string $clientId, int $sacs, array $credit = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/sorties', array_merge([
            'type' => 'VENTE',
            'clientId' => $clientId,
            'lignes' => [['varianteId' => $ciment->id, 'quantite' => $sacs, 'prixUnitaire' => 5000]],
        ], $credit));
    }

    public function test_une_vente_a_credit_sort_le_stock_sans_encaisser(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $ciment = $this->ciment($admin);
        $client = $this->client();

        $this->venteACredit($ciment, $client, 10, ['echeanceJours' => 15])->assertStatus(201)->assertJsonPath('data.totalMontant', '50000.00');

        $this->assertEqualsWithDelta(90, $ciment->fresh()->quantite_stock, 0.001);
        $this->assertSame(0, Transaction::count());
        $this->getJson("/api/v1/clients/{$client}")
            ->assertStatus(200)
            ->assertJsonPath('data.solde', '50000.00')
            ->assertJsonPath('data.enRetard', '0.00')
            ->assertJsonPath('data.prochaineEcheance', now()->addDays(15)->toDateString())
            ->assertJsonPath('data.operations.0.type', 'VENTE');
    }

    public function test_l_acompte_entre_en_caisse_et_reduit_la_dette(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $client = $this->client();

        $this->venteACredit($this->ciment($admin), $client, 10, ['acompteMontant' => 20000, 'acompteMode' => 'WAVE'])->assertStatus(201);

        $tx = Transaction::firstOrFail();
        $this->assertSame('20000.00', (string) $tx->montant);
        $this->assertSame('WAVE', $tx->mode_paiement);
        $this->getJson("/api/v1/clients/{$client}")->assertJsonPath('data.solde', '30000.00');

        // Un acompte qui couvre tout n'est plus du crédit.
        $this->venteACredit($this->ciment($admin), $client, 1, ['acompteMontant' => 5000])
            ->assertStatus(422)->assertJsonPath('error.code', 'ACOMPTE_TROP_ELEVE');
    }

    public function test_le_plafond_bloque_la_vente_et_ne_touche_pas_au_stock(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $ciment = $this->ciment($admin);
        $client = $this->client(['plafondCredit' => 60000]);

        $this->venteACredit($ciment, $client, 10)->assertStatus(201);
        $this->venteACredit($ciment, $client, 3)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'PLAFOND_DEPASSE')
            ->assertJsonPath('error.details.disponible', 10000);

        $this->assertEqualsWithDelta(90, $ciment->fresh()->quantite_stock, 0.001);
        $this->venteACredit($ciment, $client, 2)->assertStatus(201);
    }

    public function test_un_reglement_entre_en_caisse_et_ne_depasse_pas_la_dette(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $client = $this->client();
        $this->venteACredit($this->ciment($admin), $client, 10)->assertStatus(201);

        $this->postJson("/api/v1/clients/{$client}/reglements", ['montant' => 60000, 'modePaiement' => 'CASH'])
            ->assertStatus(422)->assertJsonPath('error.code', 'REGLEMENT_TROP_ELEVE');

        $this->postJson("/api/v1/clients/{$client}/reglements", ['montant' => 15000, 'modePaiement' => 'ORANGE_MONEY'])
            ->assertStatus(201)
            ->assertJsonPath('data.solde', '35000.00');
        $this->assertSame('15000.00', (string) Transaction::firstOrFail()->montant);

        $releve = $this->getJson("/api/v1/clients/{$client}")->json('data.operations');
        $this->assertSame(['REGLEMENT', 'VENTE'], array_column($releve, 'type'));
        $this->assertSame(['35000.00', '50000.00'], array_column($releve, 'soldeApres'));
    }

    public function test_un_reglement_demande_une_caisse_ouverte(): void
    {
        $admin = $this->actingAsAdmin();
        $client = Client::create(['boutique_id' => $admin->boutique_id, 'nom' => 'Koné BTP']);
        OperationCredit::create([
            'boutique_id' => $admin->boutique_id, 'client_id' => $client->id, 'type' => 'VENTE',
            'montant' => 10000, 'echeance' => now()->addDays(30)->toDateString(), 'user_id' => $admin->id,
        ]);

        $this->postJson("/api/v1/clients/{$client->id}/reglements", ['montant' => 5000, 'modePaiement' => 'CASH'])
            ->assertStatus(409)->assertJsonPath('error.code', 'NO_ACTIVE_SESSION');
    }

    public function test_les_reglements_soldent_d_abord_les_ventes_les_plus_anciennes(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $ciment = $this->ciment($admin);
        $client = $this->client();

        $this->venteACredit($ciment, $client, 4, ['echeanceJours' => 10])->assertStatus(201); // 20 000
        $this->travel(15)->days();
        $this->venteACredit($ciment, $client, 2, ['echeanceJours' => 30])->assertStatus(201); // 10 000
        $this->postJson("/api/v1/clients/{$client}/reglements", ['montant' => 12000, 'modePaiement' => 'CASH'])->assertStatus(201);

        // Reste 8 000 sur la 1re vente, échue depuis 5 jours ; la 2e n'est pas encore due.
        $this->getJson("/api/v1/clients/{$client}")
            ->assertJsonPath('data.solde', '18000.00')
            ->assertJsonPath('data.enRetard', '8000.00')
            ->assertJsonPath('data.joursRetard', 5)
            ->assertJsonPath('data.nbVentesOuvertes', 2);

        $this->getJson('/api/v1/clients?filtre=EN_RETARD')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/clients/{$client}/reglements", ['montant' => 8000, 'modePaiement' => 'CASH'])->assertStatus(201);
        $this->getJson('/api/v1/clients?filtre=EN_RETARD')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/clients?filtre=AVEC_SOLDE')->assertJsonCount(1, 'data');
    }

    public function test_annuler_une_vente_a_credit_efface_la_dette(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $ciment = $this->ciment($admin);
        $client = $this->client();
        $sortieId = $this->venteACredit($ciment, $client, 10)->json('data.id');

        $this->patchJson("/api/v1/sorties/{$sortieId}/annuler")->assertStatus(200);

        $this->assertEqualsWithDelta(100, $ciment->fresh()->quantite_stock, 0.001);
        $this->getJson("/api/v1/clients/{$client}")
            ->assertJsonPath('data.solde', '0.00')
            ->assertJsonPath('data.nbVentesOuvertes', 0);
    }

    public function test_un_devis_peut_devenir_une_vente_a_credit(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $ciment = $this->ciment($admin);
        $client = $this->client();

        $devisId = $this->postJson('/api/v1/devis', [
            'clientNom' => 'Koné BTP',
            'lignes' => [['varianteId' => $ciment->id, 'quantite' => 12, 'prixUnitaire' => 5500]],
        ])->json('data.id');

        $this->postJson("/api/v1/devis/{$devisId}/convertir", ['clientId' => $client, 'echeanceJours' => 30, 'acompteMontant' => 6000, 'acompteMode' => 'CASH'])
            ->assertStatus(200)
            ->assertJsonPath('data.devis.statut', 'CONVERTI');

        $this->getJson("/api/v1/clients/{$client}")->assertJsonPath('data.solde', '60000.00');
    }

    public function test_la_caisse_ne_propose_que_les_clients_a_qui_on_fait_credit(): void
    {
        $this->actingAsAdmin();
        $actif = $this->client(['nom' => 'Koné BTP']);
        $inactif = $this->client(['nom' => 'Ancien client']);
        $this->patchJson("/api/v1/clients/{$inactif}", ['isActif' => false])->assertStatus(200);

        // Le paramètre arrive en texte depuis l'URL.
        $this->getJson('/api/v1/clients?actifs=true')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $actif);
    }

    public function test_seul_l_admin_fixe_le_plafond(): void
    {
        $caissier = $this->actingAsCaissier();
        $id = $this->postJson('/api/v1/clients', ['nom' => 'Plomberie Yao', 'plafondCredit' => 500000])
            ->assertStatus(201)
            ->assertJsonPath('data.plafondCredit', null)
            ->json('data.id');

        $this->patchJson("/api/v1/clients/{$id}", ['plafondCredit' => 500000])->assertStatus(403);
        $this->postJson('/api/v1/clients', ['nom' => 'Plomberie Yao'])->assertStatus(409)->assertJsonPath('error.code', 'CLIENT_EXISTE');
        $this->assertSame($caissier->boutique_id, Client::findOrFail($id)->boutique_id);
    }

    public function test_un_client_d_une_autre_boutique_est_introuvable(): void
    {
        $admin = $this->actingAsAdmin();
        $this->ouvrirCaisse($admin);
        $autre = User::factory()->admin()->create();
        $etranger = Client::create(['boutique_id' => $autre->boutique_id, 'nom' => 'Client voisin']);

        $this->venteACredit($this->ciment($admin), $etranger->id, 1)->assertStatus(404);
        $this->getJson("/api/v1/clients/{$etranger->id}")->assertStatus(404);
    }
}
