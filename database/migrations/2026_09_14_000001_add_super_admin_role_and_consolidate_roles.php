<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Passage au modèle multi-tenant : un seul rôle de plateforme (SUPER_ADMIN)
 * au-dessus des boutiques, et exactement 2 rôles au niveau boutique
 * (ADMIN = admin de la boutique, CAISSIER = ex-VENDEUR).
 *
 * GERANT (rôle intermédiaire ajouté le 2026-07-03) est absorbé par ADMIN :
 * il avait déjà des permissions élargies proches d'un admin de boutique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        // 1) Élargir l'enum pour accepter à la fois les anciennes et nouvelles valeurs
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('ADMIN','VENDEUR','GERANT','SUPER_ADMIN','CAISSIER') NOT NULL DEFAULT 'VENDEUR'");

        // 2) Migrer les données existantes
        DB::table('users')->where('role', 'VENDEUR')->update(['role' => 'CAISSIER']);
        DB::table('users')->where('role', 'GERANT')->update(['role' => 'ADMIN']);

        // 3) Restreindre l'enum à sa forme finale
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('SUPER_ADMIN','ADMIN','CAISSIER') NOT NULL DEFAULT 'CAISSIER'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('ADMIN','VENDEUR','GERANT','SUPER_ADMIN','CAISSIER') NOT NULL DEFAULT 'CAISSIER'");
        DB::table('users')->where('role', 'CAISSIER')->update(['role' => 'VENDEUR']);
        DB::table('users')->where('role', 'SUPER_ADMIN')->update(['role' => 'ADMIN']);
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('ADMIN','VENDEUR') NOT NULL DEFAULT 'VENDEUR'");
    }
};
