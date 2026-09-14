<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rend boutique_id obligatoire sur toutes les tables opérationnelles :
 * chaque variante, entrée, sortie et session de caisse appartient désormais
 * strictement à une seule boutique-locataire (fin du "catalogue global").
 *
 * Les FK d'origine étaient en ON DELETE SET NULL (nullOnDelete()), ce que
 * MySQL refuse sur une colonne NOT NULL (erreur 1830) : on les recrée donc
 * en CASCADE avant/après le passage en NOT NULL.
 */
return new class extends Migration
{
    private const TABLES = ['variantes', 'entrees', 'sorties', 'caisse_sessions'];

    private function fallbackBoutiqueId(): ?string
    {
        return DB::table('boutiques')->orderBy('created_at')->value('id');
    }

    public function up(): void
    {
        $fallback = $this->fallbackBoutiqueId();

        // variantes : hériter de la boutique du produit
        DB::statement('
            UPDATE variantes v
            JOIN produits p ON p.id = v.produit_id
            SET v.boutique_id = p.boutique_id
            WHERE v.boutique_id IS NULL OR v.boutique_id <> p.boutique_id
        ');

        // entrees / sorties / caisse_sessions : hériter de la boutique de l'utilisateur créateur
        foreach (['entrees', 'sorties', 'caisse_sessions'] as $table) {
            DB::statement("
                UPDATE {$table} t
                JOIN users u ON u.id = t.user_id
                SET t.boutique_id = u.boutique_id
                WHERE t.boutique_id IS NULL AND u.boutique_id IS NOT NULL
            ");
            if ($fallback) {
                DB::table($table)->whereNull('boutique_id')->update(['boutique_id' => $fallback]);
            }
        }

        // Retirer les FK ON DELETE SET NULL — incompatibles avec NOT NULL
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['boutique_id']);
            });
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (self::TABLES as $table) {
                DB::statement("ALTER TABLE {$table} MODIFY COLUMN boutique_id CHAR(36) NOT NULL");
            }
        }

        // Recréer les FK en CASCADE (cohérent avec categories/produits/fournisseurs)
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['boutique_id']);
            });
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (self::TABLES as $table) {
                DB::statement("ALTER TABLE {$table} MODIFY COLUMN boutique_id CHAR(36) NULL");
            }
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('boutique_id')->references('id')->on('boutiques')->nullOnDelete();
            });
        }
    }
};
