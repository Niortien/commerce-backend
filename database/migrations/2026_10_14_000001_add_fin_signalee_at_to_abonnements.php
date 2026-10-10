<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Date à laquelle les Super Admins ont été prévenus de la fin de l'abonnement (un seul mail par abonnement). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->timestamp('fin_signalee_at')->nullable()->after('date_fin');
        });
    }

    public function down(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->dropColumn('fin_signalee_at');
        });
    }
};
