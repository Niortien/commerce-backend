<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ligne_sorties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sortie_id');
            $table->uuid('variante_id');
            $table->integer('quantite');
            $table->decimal('prix_unitaire', 10, 2);

            $table->foreign('sortie_id')->references('id')->on('sorties')->cascadeOnDelete();
            $table->foreign('variante_id')->references('id')->on('variantes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ligne_sorties');
    }
};
