<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, $roles, true)) {
            throw new DomainException('Accès refusé.', 403, 'FORBIDDEN');
        }
        return $next($request);
    }
}
