<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abonnements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('boutique_id');
            $table->enum('plan', ['ESSAI', 'MENSUEL', 'TRIMESTRIEL', 'ANNUEL']);
            $table->enum('statut', ['ACTIF', 'EXPIRE', 'SUSPENDU', 'ANNULE'])->default('ACTIF');
            $table->dateTime('date_debut');
            $table->dateTime('date_fin');
            $table->decimal('montant', 10, 2)->nullable();
            $table->string('devise', 10)->default('XOF');
            $table->text('notes')->nullable();
            $table->uuid('cree_par_id')->nullable();
            $table->timestamps();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->foreign('cree_par_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['boutique_id', 'date_fin']);
        });

        // Les boutiques déjà existantes (le commerce actuel, avant l'ouverture
        // de la plateforme à d'autres locataires) reçoivent un abonnement actif
        // de longue durée pour ne pas interrompre leur accès.
        $now = now();
        $rows = DB::table('boutiques')->get(['id', 'created_at']);
        foreach ($rows as $boutique) {
            DB::table('abonnements')->insert([
                'id'          => (string) Str::uuid(),
                'boutique_id' => $boutique->id,
                'plan'        => 'ANNUEL',
                'statut'      => 'ACTIF',
                'date_debut'  => $boutique->created_at ?? $now,
                'date_fin'    => $now->copy()->addYears(10),
                'montant'     => null,
                'devise'      => 'XOF',
                'notes'       => 'Abonnement initial généré lors du passage à la plateforme multi-boutiques.',
                'cree_par_id' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('abonnements');
    }
};
