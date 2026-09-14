<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function actingAsAdmin(?User $user = null): User
    {
        $user ??= User::factory()->admin()->create();
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
        $user ??= User::factory()->caissier()->create();
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
