<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Type de commerce de la boutique : il décide des pages et du vocabulaire de son espace.
 * Choisi à l'inscription, modifiable ensuite uniquement par le Super Admin.
 *
 * La colonne existe déjà (migration 2026_10_08_000002, défaut MODE) : l'ancien « MODE » devient
 * « VETEMENTS », l'option historique, et le type est indexé (filtre du Super Admin par secteur).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('boutiques', 'type_commerce')) {
            Schema::table('boutiques', function (Blueprint $table) {
                $table->string('type_commerce', 30)->default('VETEMENTS')->after('nom');
            });
        } else {
            DB::statement("ALTER TABLE boutiques ALTER COLUMN type_commerce SET DEFAULT 'VETEMENTS'");
            DB::table('boutiques')->where('type_commerce', 'MODE')->update(['type_commerce' => 'VETEMENTS']);
        }

        Schema::table('boutiques', function (Blueprint $table) {
            $table->index('type_commerce');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropIndex(['type_commerce']);
        });
        DB::table('boutiques')->where('type_commerce', 'VETEMENTS')->update(['type_commerce' => 'MODE']);
        DB::statement("ALTER TABLE boutiques ALTER COLUMN type_commerce SET DEFAULT 'MODE'");
    }
};
