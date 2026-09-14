<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Réinitialise le mot de passe d'un utilisateur existant sans passer par
 * tinker (indisponible sur certains hébergements mutualisés où
 * shell_exec() est désactivé, ce dont Psysh a besoin au démarrage).
 *
 * Usage :
 *   php artisan user:reset-password admin@exemple.com "NouveauMotDePasse123!"
 */
class ResetUserPassword extends Command
{
    protected $signature = 'user:reset-password {email} {password}';
    protected $description = "Réinitialise le mot de passe d'un utilisateur par email";

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->argument('password');

        if (strlen($password) < 8) {
            $this->error('Le mot de passe doit contenir au moins 8 caractères.');
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("Aucun utilisateur trouvé avec l'email : {$email}");
            return self::FAILURE;
        }

        $user->update(['password_hash' => Hash::make($password)]);

        $this->info("Mot de passe mis à jour pour {$user->email} (rôle : {$user->role}).");
        return self::SUCCESS;
    }
}
