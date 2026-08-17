<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('sortie_id')->nullable()->unique();
            $table->decimal('montant', 10, 2);
            $table->enum('mode_paiement', ['CASH', 'WAVE', 'ORANGE_MONEY', 'CARTE', 'MTN_MONEY']);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('session_id')->references('id')->on('caisse_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
