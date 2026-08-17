<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mouvement_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('variante_id');
            $table->enum('type', ['ENTREE', 'SORTIE', 'AJUSTEMENT', 'RETOUR']);
            $table->integer('quantite');
            $table->string('motif')->nullable();
            $table->string('reference_entree')->nullable();
            $table->string('reference_sortie')->nullable();
            $table->uuid('user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('variante_id')->references('id')->on('variantes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvement_stocks');
    }
};
