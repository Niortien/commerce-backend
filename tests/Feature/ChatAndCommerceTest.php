<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatAndCommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inscription_enregistre_telephone_admin_et_type_commerce(): void
    {
        $this->postJson('/api/v1/auth/inscription-boutique', [
            'nomBoutique'  => 'Pharma Plus',
            'typeCommerce' => 'PHARMACIE',
            'telephone'    => '+2250700000000',
            'email'        => 'admin@pharma.test',
            'password'     => 'password123',
        ])->assertStatus(201);

        $this->assertSame('PHARMACIE', Boutique::where('nom', 'Pharma Plus')->first()->type_commerce);
        $this->assertSame('+2250700000000', User::where('email', 'admin@pharma.test')->first()->telephone);
    }

    public function test_type_commerce_invalide_est_refuse_et_defaut_mode(): void
    {
        $this->postJson('/api/v1/auth/inscription-boutique', [
            'nomBoutique' => 'X', 'typeCommerce' => 'NOPE', 'email' => 'a@b.test', 'password' => 'password123',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/inscription-boutique', [
            'nomBoutique' => 'Sans type', 'email' => 'c@d.test', 'password' => 'password123',
        ])->assertStatus(201);
        $this->assertSame('MODE', Boutique::where('nom', 'Sans type')->first()->type_commerce);
    }

    public function test_super_admin_voit_le_telephone_des_admins(): void
    {
        User::factory()->admin()->create(['telephone' => '0102030405']);
        $this->actingAsSuperAdmin();

        $res = $this->getJson('/api/v1/super-admin/users?role=ADMIN')->assertOk();
        $this->assertSame('0102030405', $res->json('data.0.telephone'));
    }

    public function test_admin_modifie_le_type_commerce_de_sa_boutique(): void
    {
        $admin = $this->actingAsAdmin();
        $admin->boutique->update(['statut' => 'ACTIF', 'is_active' => true]);

        $this->patchJson('/api/v1/boutiques/me', ['typeCommerce' => 'ALIMENTATION'])
            ->assertOk()
            ->assertJsonPath('data.typeCommerce', 'ALIMENTATION');
    }

    public function test_super_admin_ouvre_une_conversation_et_ecrit(): void
    {
        $admin = User::factory()->admin()->create(['telephone' => '0102030405']);
        $this->actingAsSuperAdmin();

        $conv = $this->postJson('/api/v1/chat/conversations', ['adminId' => $admin->id])
            ->assertStatus(201)
            ->assertJsonPath('data.admin.telephone', '0102030405')
            ->json('data');

        // Ré-ouvrir renvoie la même conversation
        $this->postJson('/api/v1/chat/conversations', ['adminId' => $admin->id])->assertJsonPath('data.id', $conv['id']);

        $this->postJson("/api/v1/chat/conversations/{$conv['id']}/messages", ['body' => '  Bonjour  '])
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Bonjour')
            ->assertJsonPath('data.senderRole', 'SUPER_ADMIN');

        $this->postJson("/api/v1/chat/conversations/{$conv['id']}/messages", ['body' => '   '])->assertStatus(422);
        $this->getJson('/api/v1/chat/conversations')->assertOk()->assertJsonPath('data.0.lastMessage.body', 'Bonjour');
    }

    public function test_admin_lit_les_messages_du_super_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $conv = ChatConversation::create(['admin_id' => $admin->id]);
        $conv->messages()->create(['sender_id' => $superAdmin->id, 'body' => 'Bonjour']);

        $this->actingAsAdmin($admin);
        $list = $this->getJson('/api/v1/chat/conversations')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($conv->id, $list[0]['id']);
        $this->assertSame(1, $list[0]['unreadCount']);

        $this->getJson("/api/v1/chat/conversations/{$conv->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Bonjour')
            ->assertJsonPath('data.0.senderRole', 'SUPER_ADMIN');

        $this->postJson("/api/v1/chat/conversations/{$conv->id}/read")->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/chat/conversations')->json('data.0.unreadCount'));

        $this->postJson("/api/v1/chat/conversations/{$conv->id}/messages", ['body' => 'Merci'])
            ->assertStatus(201)
            ->assertJsonPath('data.senderRole', 'ADMIN');
    }

    public function test_un_admin_ne_peut_pas_lire_la_conversation_d_un_autre(): void
    {
        $a = User::factory()->admin()->create();
        $convA = ChatConversation::create(['admin_id' => $a->id]);

        $this->actingAsAdmin(User::factory()->admin()->create());
        $this->getJson("/api/v1/chat/conversations/{$convA->id}/messages")->assertStatus(404);
        $this->postJson("/api/v1/chat/conversations/{$convA->id}/messages", ['body' => 'x'])->assertStatus(404);
        $this->assertCount(1, $this->getJson('/api/v1/chat/conversations')->json('data'));
    }

    public function test_un_admin_ne_peut_pas_ouvrir_de_conversation(): void
    {
        $admin = $this->actingAsAdmin();
        $this->postJson('/api/v1/chat/conversations', ['adminId' => $admin->id])->assertStatus(403);
    }

    public function test_un_caissier_n_a_pas_acces_au_chat(): void
    {
        $this->actingAsCaissier();
        $this->getJson('/api/v1/chat/conversations')->assertStatus(403);
    }
}
