<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friperie :
 * - tri par qualité : chaque pièce a un « choix » (1er, 2e, 3e) et la balle un prix conseillé par choix ;
 * - démarque : une pièce qui traîne en rayon voit son prix baisser ; on garde son prix d'origine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedTinyInteger('choix')->nullable()->after('piece_unique');
            $table->decimal('prix_initial', 12, 2)->nullable()->after('prix_vente');
            $table->timestamp('derniere_demarque_at')->nullable()->after('choix');
            $table->unsignedSmallInteger('nb_demarques')->default(0)->after('derniere_demarque_at');
        });

        Schema::table('balles', function (Blueprint $table) {
            $table->decimal('prix_choix_1', 12, 2)->nullable()->after('frais');
            $table->decimal('prix_choix_2', 12, 2)->nullable()->after('prix_choix_1');
            $table->decimal('prix_choix_3', 12, 2)->nullable()->after('prix_choix_2');
        });
    }

    public function down(): void
    {
        Schema::table('balles', function (Blueprint $table) {
            $table->dropColumn(['prix_choix_1', 'prix_choix_2', 'prix_choix_3']);
        });
        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn(['choix', 'prix_initial', 'derniere_demarque_at', 'nb_demarques']);
        });
    }
};
