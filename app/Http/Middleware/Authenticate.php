<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * API uniquement : pas de page de connexion vers laquelle rediriger.
     * null laisse passer l'AuthenticationException, rendue en 401 JSON par le Handler,
     * même quand le client n'envoie pas « Accept: application/json ».
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
