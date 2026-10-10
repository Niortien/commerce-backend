<?php

use App\Models\Boutique;
use App\Services\CategoriesDeDepart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Codes-barres : chaque variante peut avoir son code (lu au scanner ou à la caméra).
 * - Ventes hors connexion : une vente faite sans internet garde la référence créée sur l'appareil,
 *   pour qu'un second envoi ne l'enregistre jamais deux fois.
 * - Catégories de départ : les boutiques déjà inscrites sans aucune catégorie reçoivent celles de leur type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variantes', function (Blueprint $table) {
            $table->string('code_barre', 64)->nullable()->after('couleur');
            $table->unique(['boutique_id', 'code_barre']);
        });

        Schema::table('sorties', function (Blueprint $table) {
            $table->string('client_ref', 64)->nullable()->after('reference');
            $table->unique(['boutique_id', 'client_ref']);
        });

        $depart = app(CategoriesDeDepart::class);
        Boutique::whereDoesntHave('categories')->each(fn(Boutique $b) => $depart->creer($b));
    }

    public function down(): void
    {
        Schema::table('sorties', function (Blueprint $table) {
            $table->dropUnique(['boutique_id', 'client_ref']);
            $table->dropColumn('client_ref');
        });
        Schema::table('variantes', function (Blueprint $table) {
            $table->dropUnique(['boutique_id', 'code_barre']);
            $table->dropColumn('code_barre');
        });
    }
};
