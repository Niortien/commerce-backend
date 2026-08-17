<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sorties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->enum('type', ['VENTE', 'PERTE', 'DON', 'RETOUR_FOURNISSEUR']);
            $table->decimal('total_avant_remise', 10, 2)->nullable();
            $table->decimal('remise_montant', 10, 2)->nullable();
            $table->decimal('total_montant', 10, 2);
            $table->text('notes')->nullable();
            $table->uuid('user_id');
            $table->uuid('boutique_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('boutique_id')->references('id')->on('boutiques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorties');
    }
};
