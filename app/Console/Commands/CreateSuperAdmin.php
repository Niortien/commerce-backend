<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Crée (ou met à jour) un compte SUPER_ADMIN sans passer par tinker
 * (indisponible sur cet hébergement — shell_exec() désactivé, dont Psysh a
 * besoin au démarrage). Utile notamment pour rebootstraper une base vidée,
 * où aucun SUPER_ADMIN n'existe encore pour passer par l'API.
 *
 * Usage :
 *   php artisan user:create-super-admin admin@exemple.com "MotDePasse123"
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'user:create-super-admin {email} {password}';
    protected $description = 'Crée ou met à jour un compte SUPER_ADMIN par email';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        if (strlen($password) < 8) {
            $this->error('Le mot de passe doit contenir au moins 8 caractères.');
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['password_hash' => Hash::make($password), 'role' => 'SUPER_ADMIN', 'boutique_id' => null]
        );

        $this->info(($user->wasRecentlyCreated ? 'Compte créé' : 'Compte mis à jour') . " : {$user->email} (SUPER_ADMIN), id={$user->id}");
        return self::SUCCESS;
    }
}
