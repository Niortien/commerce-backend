<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friperie : une balle est achetée en bloc puis déballée pièce par pièce.
 * Son coût (achat + frais) se répartit sur les pièces trouvées dedans ; chaque pièce est unique
 * (une seule en stock, « vendue » dès qu'elle passe en caisse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id');
            // Numéro court par boutique (« Balle n°7 »), repris dans le code des pièces (B7-014).
            $table->unsignedInteger('numero');
            $table->string('libelle', 150);
            $table->string('fournisseur', 150)->nullable();
            $table->uuid('fournisseur_id')->nullable();
            $table->decimal('cout_achat', 12, 2);
            $table->decimal('frais', 12, 2)->default(0);
            $table->date('date_achat');
            $table->string('statut', 12)->default('EN_COURS');
            // Dépense enregistrée côté entrées (trésorerie, rapports).
            $table->uuid('entree_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('user_id');
            $table->timestamps();

            $table->unique(['boutique_id', 'numero']);
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('entree_id')->references('id')->on('entrees')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('produits', function (Blueprint $table) {
            $table->uuid('balle_id')->nullable()->after('categorie_id');
            $table->unsignedInteger('numero_piece')->nullable()->after('balle_id');
            $table->boolean('piece_unique')->default(false)->after('numero_piece');

            $table->index('balle_id');
            $table->foreign('balle_id')->references('id')->on('balles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->dropForeign(['balle_id']);
            $table->dropIndex(['balle_id']);
            $table->dropColumn(['balle_id', 'numero_piece', 'piece_unique']);
        });
        Schema::dropIfExists('balles');
    }
};
