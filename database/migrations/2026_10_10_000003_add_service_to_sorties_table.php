<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant : une vente se fait sur place (avec un numéro de table), à emporter ou en livraison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sorties', function (Blueprint $table) {
            $table->string('mode_service', 12)->nullable()->after('type');
            $table->string('table_label', 30)->nullable()->after('mode_service');
        });
    }

    public function down(): void
    {
        Schema::table('sorties', function (Blueprint $table) {
            $table->dropColumn(['mode_service', 'table_label']);
        });
    }
};
