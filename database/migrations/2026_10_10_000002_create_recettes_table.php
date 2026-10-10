<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche technique d'un plat (restaurant) : les ingrédients et leur quantité pour UNE portion.
 * Vendre le plat retire ces quantités du stock des ingrédients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recettes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plat_id');
            $table->uuid('ingredient_variante_id');
            $table->decimal('quantite', 12, 3);
            $table->timestamps();

            $table->unique(['plat_id', 'ingredient_variante_id']);
            $table->foreign('plat_id')->references('id')->on('produits')->cascadeOnDelete();
            $table->foreign('ingredient_variante_id')->references('id')->on('variantes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recettes');
    }
};
