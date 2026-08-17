<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lookbook_photos', function (Blueprint $table) {
            $table->boolean('publiee')->default(false)->after('statut');
            $table->index('publiee');
        });
    }

    public function down(): void
    {
        Schema::table('lookbook_photos', function (Blueprint $table) {
            $table->dropIndex(['publiee']);
            $table->dropColumn('publiee');
        });
    }
};
