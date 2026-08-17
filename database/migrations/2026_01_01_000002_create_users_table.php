<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->enum('role', ['ADMIN', 'VENDEUR'])->default('VENDEUR');
            $table->uuid('boutique_id')->nullable();
            $table->timestamps();

            $table->foreign('boutique_id')->references('id')->on('boutiques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
