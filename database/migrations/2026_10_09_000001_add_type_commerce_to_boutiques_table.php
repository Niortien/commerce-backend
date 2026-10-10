<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Type de commerce de la boutique (VETEMENTS, RESTAURANT, QUINCAILLERIE, FRIPERIE) :
 * il décide des pages et du vocabulaire de son espace. Choisi à l'inscription,
 * modifiable ensuite uniquement par le Super Admin. Les boutiques existantes
 * deviennent des boutiques de vêtements, l'option historique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('type_commerce', 20)->default('VETEMENTS')->after('nom');
            $table->index('type_commerce');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropIndex(['type_commerce']);
            $table->dropColumn('type_commerce');
        });
    }
};
