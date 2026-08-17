<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('caisse_sessions', function (Blueprint $table) {
            $table->decimal('montant_theorique', 10, 2)->nullable()->after('montant_fermeture');
            $table->decimal('ecart', 10, 2)->nullable()->after('montant_theorique');
        });
    }

    public function down(): void
    {
        Schema::table('caisse_sessions', function (Blueprint $table) {
            $table->dropColumn(['montant_theorique', 'ecart']);
        });
    }
};
