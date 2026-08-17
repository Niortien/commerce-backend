<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('ADMIN', 'VENDEUR', 'GERANT') NOT NULL DEFAULT 'VENDEUR'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('ADMIN', 'VENDEUR') NOT NULL DEFAULT 'VENDEUR'");
    }
};
