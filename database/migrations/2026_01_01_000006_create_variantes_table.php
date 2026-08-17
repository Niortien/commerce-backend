<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('variantes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('produit_id');
            $table->uuid('boutique_id')->nullable();
            $table->string('taille');
            $table->string('couleur');
            $table->integer('quantite_stock')->default(0);
            $table->integer('seuil_alerte')->default(5);
            $table->timestamps();

            $table->unique(['produit_id', 'taille', 'couleur', 'boutique_id']);
            $table->foreign('produit_id')->references('id')->on('produits')->cascadeOnDelete();
            $table->foreign('boutique_id')->references('id')->on('boutiques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variantes');
    }
};
