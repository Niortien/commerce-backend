<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom');
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->uuid('categorie_id');
            $table->decimal('prix_vente', 10, 2);
            $table->decimal('prix_achat', 10, 2);
            $table->string('image_url')->nullable();
            $table->boolean('is_actif')->default(true);
            $table->boolean('en_promo')->default(false);
            $table->decimal('prix_promo', 10, 2)->nullable();
            $table->dateTime('date_debut_promo')->nullable();
            $table->dateTime('date_fin_promo')->nullable();
            $table->timestamps();

            $table->foreign('categorie_id')->references('id')->on('categories');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
