<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FULLTEXT sur produits.nom pour les recherches LIKE '%term%'
        // MySQL utilise MATCH AGAINST automatiquement quand le moteur supporte FULLTEXT.
        // L'index B-tree sur nom seul ne sert pas pour les wildcards préfixés.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE produits ADD FULLTEXT INDEX ft_produits_nom (nom)');
            DB::statement('ALTER TABLE entrees ADD FULLTEXT INDEX ft_entrees_fournisseur (fournisseur)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE produits DROP INDEX ft_produits_nom');
            DB::statement('ALTER TABLE entrees DROP INDEX ft_entrees_fournisseur');
        }
    }
};
