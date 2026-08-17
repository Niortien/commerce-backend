<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lookbook_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('url');
            $table->string('nom')->nullable();
            $table->string('telephone')->nullable();
            $table->string('message')->nullable();
            $table->enum('statut', ['nouveau', 'vu', 'traite'])->default('nouveau');
            $table->timestamps();

            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookbook_photos');
    }
};
