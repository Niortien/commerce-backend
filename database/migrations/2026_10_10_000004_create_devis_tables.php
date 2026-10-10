<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devis / factures proforma (quincaillerie) : prix promis à un client, sans toucher au stock.
 * Un devis accepté se transforme en vente (sortie_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devis', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id');
            $table->string('reference')->unique();
            $table->string('client_nom', 150);
            $table->string('client_telephone', 40)->nullable();
            $table->string('statut', 12)->default('EN_COURS');
            $table->date('valable_jusqu_au');
            $table->decimal('total_avant_remise', 12, 2);
            $table->decimal('remise_montant', 12, 2)->default(0);
            $table->decimal('total_montant', 12, 2);
            $table->text('notes')->nullable();
            $table->uuid('sortie_id')->nullable();
            $table->uuid('user_id');
            $table->timestamps();

            $table->index(['boutique_id', 'statut']);
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('sortie_id')->references('id')->on('sorties')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('ligne_devis', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('devis_id');
            $table->uuid('variante_id');
            // Copie du nom au moment du devis : le document reste lisible si le produit change.
            $table->string('designation');
            $table->decimal('quantite', 12, 3);
            $table->decimal('prix_unitaire', 12, 2);

            $table->foreign('devis_id')->references('id')->on('devis')->cascadeOnDelete();
            $table->foreign('variante_id')->references('id')->on('variantes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ligne_devis');
        Schema::dropIfExists('devis');
    }
};
