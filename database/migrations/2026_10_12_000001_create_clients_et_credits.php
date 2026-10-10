<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crédit clients (quincaillerie) : un client pro prend la marchandise maintenant et paie plus tard.
 * Son compte est la suite de ses opérations : ventes à crédit (dette), règlements (paiements reçus)
 * et annulations de vente. Le solde n'est jamais stocké : il se recalcule depuis ces opérations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id');
            $table->string('nom', 150);
            $table->string('telephone', 40)->nullable();
            // Encours maximum autorisé ; null = pas de limite.
            $table->decimal('plafond_credit', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_actif')->default(true);
            $table->timestamps();

            $table->unique(['boutique_id', 'nom']);
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
        });

        Schema::create('operations_credit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id');
            $table->uuid('client_id');
            // VENTE (le client doit), REGLEMENT (il a payé), ANNULATION (une vente à crédit annulée).
            $table->string('type', 12);
            $table->decimal('montant', 12, 2);
            $table->uuid('sortie_id')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->string('mode_paiement', 20)->nullable();
            $table->date('echeance')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('user_id');
            $table->timestamps();

            $table->index(['client_id', 'created_at']);
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('sortie_id')->references('id')->on('sorties')->nullOnDelete();
            $table->foreign('transaction_id')->references('id')->on('transactions')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('sorties', function (Blueprint $table) {
            $table->uuid('client_id')->nullable()->after('boutique_id');
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sorties', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });
        Schema::dropIfExists('operations_credit');
        Schema::dropIfExists('clients');
    }
};
