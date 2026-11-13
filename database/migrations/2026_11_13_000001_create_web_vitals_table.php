<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vitesse réelle du site (Web Vitals) mesurée chez les visiteurs : une ligne par mesure.
 * Aucune donnée personnelle : ni utilisateur, ni adresse IP, et la page est enregistrée sans identifiant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_vitals', function (Blueprint $table) {
            $table->id();
            $table->string('metrique', 8);              // LCP, INP, CLS, FCP, TTFB
            $table->double('valeur');                   // millisecondes (CLS : sans unité)
            $table->string('note', 20);                 // good, needs-improvement, poor
            $table->string('page', 120);                // ex. /boutique/[slug], /produits/[id]
            $table->string('appareil', 10);             // mobile, ordinateur
            $table->string('connexion', 10)->nullable(); // 4g, 3g, 2g, slow-2g
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'metrique']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_vitals');
    }
};
