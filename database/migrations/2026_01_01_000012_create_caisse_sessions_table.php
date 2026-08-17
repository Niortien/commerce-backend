<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('caisse_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('boutique_id')->nullable();
            $table->dateTime('date_ouverture');
            $table->dateTime('date_fermeture')->nullable();
            $table->decimal('montant_ouverture', 10, 2);
            $table->decimal('montant_fermeture', 10, 2)->nullable();
            $table->enum('statut', ['OUVERTE', 'FERMEE'])->default('OUVERTE');

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('boutique_id')->references('id')->on('boutiques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caisse_sessions');
    }
};
