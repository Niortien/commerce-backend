<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Crée (ou met à jour) le premier compte Super Admin de la plateforme.
 * Le SUPER_ADMIN n'appartient à aucune boutique (boutique_id = null).
 *
 * Usage :
 *   SUPER_ADMIN_EMAIL=vous@exemple.com SUPER_ADMIN_PASSWORD=UnMotDePasseFort! \
 *     php artisan db:seed --class=SuperAdminSeeder
 *
 * Sans variables d'environnement, des valeurs par défaut sont utilisées —
 * changez le mot de passe immédiatement après la première connexion.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('SUPER_ADMIN_EMAIL', 'superadmin@plateforme.com');
        $password = env('SUPER_ADMIN_PASSWORD', 'ChangeMoiImmediatement123!');

        $user = User::updateOrCreate(
            ['email' => $email],
            ['password_hash' => Hash::make($password), 'role' => 'SUPER_ADMIN', 'boutique_id' => null]
        );

        $this->command?->info("Super Admin prêt : {$user->email}");
        if (!env('SUPER_ADMIN_PASSWORD')) {
            $this->command?->warn('Mot de passe par défaut utilisé — changez-le dès la première connexion.');
        }
    }
}
