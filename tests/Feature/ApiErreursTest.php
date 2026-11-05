<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Les erreurs de l'API restent en JSON et ne livrent aucun détail technique,
 * quel que soit l'en-tête Accept envoyé par le client.
 */
class ApiErreursTest extends TestCase
{
    public function test_route_protegee_sans_accept_json_repond_401_json(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_route_protegee_avec_faux_jeton_repond_401_json(): void
    {
        $this->withHeader('Authorization', 'Bearer faux')
            ->get('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_erreur_500_ne_montre_ni_classe_ni_message_ni_fichier(): void
    {
        Route::get('/api/test-erreur-interne', fn () => throw new \RuntimeException('secret-interne'));

        $response = $this->get('/api/test-erreur-interne')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_SERVER_ERROR')
            ->assertJsonPath('error.details', null);

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret-interne', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('.php', $body);
    }
}
