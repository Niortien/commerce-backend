<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom')->unique();
            $table->string('telephone')->nullable();
            $table->string('adresse')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('entrees', function (Blueprint $table) {
            $table->uuid('fournisseur_id')->nullable()->after('fournisseur');
            $table->foreign('fournisseur_id')->references('id')->on('fournisseurs')->nullOnDelete();
            $table->index('fournisseur_id');
        });
    }

    public function down(): void
    {
        Schema::table('entrees', function (Blueprint $table) {
            $table->dropForeign(['fournisseur_id']);
            $table->dropColumn('fournisseur_id');
        });
        Schema::dropIfExists('fournisseurs');
    }
};
