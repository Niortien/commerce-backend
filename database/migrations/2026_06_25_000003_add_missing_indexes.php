<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // transactions.session_id : utilisé dans TOUS les JOIN/whereHas boutique
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('session_id');
        });

        // mouvement_stocks : type+created_at composite (topProduits, fluxTresorerie)
        // variante_id : JOIN dans topProduits
        Schema::table('mouvement_stocks', function (Blueprint $table) {
            $table->index(['type', 'created_at']);
            $table->index('variante_id');
        });

        // variantes : boutique_id (filtre principal), produit_id (eager load)
        Schema::table('variantes', function (Blueprint $table) {
            $table->index('boutique_id');
            $table->index('produit_id');
        });

        // entrees/sorties : boutique_id + created_at (fluxTresorerie, listings)
        Schema::table('entrees', function (Blueprint $table) {
            $table->index(['boutique_id', 'created_at']);
        });

        Schema::table('sorties', function (Blueprint $table) {
            $table->index(['boutique_id', 'created_at']);
        });

        // caisse_sessions : boutique_id (JOINs remplaçant whereHas)
        Schema::table('caisse_sessions', function (Blueprint $table) {
            $table->index('boutique_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', fn($t) => $t->dropIndex(['session_id']));
        Schema::table('mouvement_stocks', function ($t) {
            $t->dropIndex(['type', 'created_at']);
            $t->dropIndex(['variante_id']);
        });
        Schema::table('variantes', function ($t) {
            $t->dropIndex(['boutique_id']);
            $t->dropIndex(['produit_id']);
        });
        Schema::table('entrees', fn($t) => $t->dropIndex(['boutique_id', 'created_at']));
        Schema::table('sorties', fn($t) => $t->dropIndex(['boutique_id', 'created_at']));
        Schema::table('caisse_sessions', fn($t) => $t->dropIndex(['boutique_id']));
    }
};
