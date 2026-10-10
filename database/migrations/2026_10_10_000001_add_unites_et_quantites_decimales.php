<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Socle des commerces hors vêtements :
 * - unité de vente d'un produit (pièce, kg, mètre, litre, sac…) ;
 * - nature (ARTICLE vendu tel quel, PLAT préparé à partir d'ingrédients, INGREDIENT acheté mais jamais vendu) ;
 * - conditionnement d'achat (ex. 1 carton = 24 pièces) pour vendre au détail ce qu'on achète en gros ;
 * - quantités décimales (12,5 m de câble, 0,250 kg de sel). Les quantités entières existantes restent identiques.
 */
return new class extends Migration
{
    private const QUANTITES = [
        ['variantes', 'quantite_stock', '0'],
        ['variantes', 'seuil_alerte', '5'],
        ['mouvement_stocks', 'quantite', null],
        ['ligne_sorties', 'quantite', null],
        ['ligne_entrees', 'quantite', null],
    ];

    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('unite', 12)->default('PIECE')->after('prix_achat');
            $table->string('nature', 12)->default('ARTICLE')->after('unite');
            $table->string('conditionnement_unite', 12)->nullable()->after('nature');
            $table->decimal('conditionnement_quantite', 12, 3)->nullable()->after('conditionnement_unite');
            $table->index(['boutique_id', 'nature']);
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (self::QUANTITES as [$table, $column, $default]) {
                $suffix = $default === null ? '' : " DEFAULT {$default}";
                DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} DECIMAL(12,3) NOT NULL{$suffix}");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (self::QUANTITES as [$table, $column, $default]) {
                $suffix = $default === null ? '' : " DEFAULT {$default}";
                DB::statement("ALTER TABLE {$table} MODIFY COLUMN {$column} INT NOT NULL{$suffix}");
            }
        }

        Schema::table('produits', function (Blueprint $table) {
            $table->dropIndex(['boutique_id', 'nature']);
            $table->dropColumn(['unite', 'nature', 'conditionnement_unite', 'conditionnement_quantite']);
        });
    }
};
