<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Isole le catalogue produits par boutique. Les produits existants héritent
 * de la boutique de leur catégorie (déjà migrée), ou de la boutique par
 * défaut à défaut de variante/catégorie exploitable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->uuid('boutique_id')->nullable()->after('id');
        });

        // 1) hériter de la boutique de la catégorie
        DB::statement('
            UPDATE produits p
            JOIN categories c ON c.id = p.categorie_id
            SET p.boutique_id = c.boutique_id
            WHERE p.boutique_id IS NULL
        ');

        // 2) filet de sécurité : hériter de la boutique d'une de ses variantes existantes
        DB::statement('
            UPDATE produits p
            JOIN variantes v ON v.produit_id = p.id AND v.boutique_id IS NOT NULL
            SET p.boutique_id = v.boutique_id
            WHERE p.boutique_id IS NULL
        ');

        // 3) dernier filet : la boutique la plus ancienne (ne devrait normalement jamais servir)
        $fallback = DB::table('boutiques')->orderBy('created_at')->value('id');
        if ($fallback) {
            DB::table('produits')->whereNull('boutique_id')->update(['boutique_id' => $fallback]);
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE produits MODIFY COLUMN boutique_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE produits DROP INDEX produits_sku_unique');
        }

        Schema::table('produits', function (Blueprint $table) {
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->unique(['boutique_id', 'sku']);
            $table->index('boutique_id');
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropUnique(['boutique_id', 'sku']);
            $table->dropForeign(['boutique_id']);
            $table->dropIndex(['boutique_id']);
            $table->dropColumn('boutique_id');
        });
        Schema::table('produits', function (Blueprint $table) {
            $table->unique('sku');
        });
    }
};
