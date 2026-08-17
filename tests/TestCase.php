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
        $user ??= User::factory()->vendeur()->create();
        $token = JWTAuth::fromUser($user);
        $this->withToken($token);
        return $user;
    }
}
