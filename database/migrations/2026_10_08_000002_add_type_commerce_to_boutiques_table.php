<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Type de commerce du locataire (adapte les libellés/variantes côté front). Les boutiques existantes restent en MODE. */
return new class extends Migration
{
    public function up(): void
    {
        // Une base qui a déjà la colonne (créée par 2026_10_09_000001 avant cette fusion) n'est pas touchée.
        if (Schema::hasColumn('boutiques', 'type_commerce')) return;

        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('type_commerce', 30)->default('MODE')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn('type_commerce');
        });
    }
};
