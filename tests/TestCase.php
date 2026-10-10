<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        // Les limites de tentatives (connexion, inscription) ne doivent pas se cumuler d'un test à l'autre :
        // en CI le cache est sur fichier et survit entre les tests.
        $this->app['cache']->store()->flush();
    }

    /** Type de commerce donné aux boutiques des comptes de test (null : celui de la fabrique). */
    protected ?string $typeCommerce = null;

    /** Donne un type de commerce à la boutique du compte (les fonctions métier en dépendent). */
    protected function metier(User $user, string $type): User
    {
        $user->boutique?->update(['type_commerce' => $type]);
        return $user;
    }

    private function avecMetier(User $user): User
    {
        return $this->typeCommerce ? $this->metier($user, $this->typeCommerce) : $user;
    }

    protected function actingAsAdmin(?User $user = null): User
    {
        $user ??= $this->avecMetier(User::factory()->admin()->create());
        $token = JWTAuth::fromUser($user);
        $this->withToken($token);
        return $user;
    }

    protected function actingAsVendeur(?User $user = null): User
    {
        return $this->actingAsCaissier($user);
    }

    protected function actingAsCaissier(?User $user = null): User
    {
        $user ??= $this->avecMetier(User::factory()->caissier()->create());
        $token = JWTAuth::fromUser($user);
        $this->withToken($token);
        return $user;
    }

    protected function actingAsSuperAdmin(?User $user = null): User
    {
        $user ??= User::factory()->superAdmin()->create();
        $token = JWTAuth::fromUser($user);
        $this->withToken($token);
        return $user;
    }
}
