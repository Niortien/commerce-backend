<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les fournisseurs sont désormais propres à chaque boutique-locataire
 * (deux commerces indépendants ne doivent pas partager leur carnet fournisseurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->uuid('boutique_id')->nullable()->after('id');
        });

        // Hériter de la boutique via les entrées déjà rattachées à ce fournisseur
        DB::statement('
            UPDATE fournisseurs f
            JOIN entrees e ON e.fournisseur_id = f.id AND e.boutique_id IS NOT NULL
            SET f.boutique_id = e.boutique_id
            WHERE f.boutique_id IS NULL
        ');

        $fallback = DB::table('boutiques')->orderBy('created_at')->value('id');
        if ($fallback) {
            DB::table('fournisseurs')->whereNull('boutique_id')->update(['boutique_id' => $fallback]);
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE fournisseurs MODIFY COLUMN boutique_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE fournisseurs DROP INDEX fournisseurs_nom_unique');
        }

        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->unique(['boutique_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->dropUnique(['boutique_id', 'nom']);
            $table->dropForeign(['boutique_id']);
            $table->dropColumn('boutique_id');
        });
        Schema::table('fournisseurs', function (Blueprint $table) {
            $table->unique('nom');
        });
    }
};
