<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Liste les comptes existants (email, rôle, boutique) — pratique pour
 * retrouver un compte admin sans accès à tinker/SQL direct.
 */
class ListUsers extends Command
{
    protected $signature = 'user:list';
    protected $description = 'Liste tous les utilisateurs avec leur rôle et leur boutique';

    public function handle(): int
    {
        $users = User::with('boutique')->orderBy('role')->orderBy('email')->get();

        if ($users->isEmpty()) {
            $this->warn('Aucun utilisateur en base.');
            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Rôle', 'Boutique'],
            $users->map(fn (User $u) => [$u->email, $u->role, $u->boutique?->nom ?? '—'])
        );

        return self::SUCCESS;
    }
}
