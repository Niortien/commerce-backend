<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('produit_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('produit_id');
            $table->string('url');
            $table->integer('ordre')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('produit_id')->references('id')->on('produits')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_images');
    }
};
