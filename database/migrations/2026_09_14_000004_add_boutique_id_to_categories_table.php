<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Isole le catalogue par boutique : chaque boutique-locataire gère ses
 * propres catégories, indépendamment des autres. Les catégories existantes
 * (catalogue jusqu'ici partagé) sont rattachées à la boutique historique.
 */
return new class extends Migration
{
    private function defaultBoutiqueId(): ?string
    {
        $existing = DB::table('boutiques')->orderBy('created_at')->value('id');
        if ($existing) return $existing;

        if (DB::table('categories')->count() === 0) return null;

        $id = (string) Str::uuid();
        DB::table('boutiques')->insert([
            'id'         => $id,
            'nom'        => 'Boutique principale',
            'slug'       => 'boutique-principale',
            'is_active'  => true,
            'statut'     => 'ACTIF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->uuid('boutique_id')->nullable()->after('id');
        });

        $defaultBoutiqueId = $this->defaultBoutiqueId();
        if ($defaultBoutiqueId) {
            DB::table('categories')->whereNull('boutique_id')->update(['boutique_id' => $defaultBoutiqueId]);
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE categories MODIFY COLUMN boutique_id CHAR(36) NOT NULL');
            // Le slug n'a plus besoin d'être unique globalement, seulement par boutique.
            DB::statement('ALTER TABLE categories DROP INDEX categories_slug_unique');
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('boutique_id')->references('id')->on('boutiques')->cascadeOnDelete();
            $table->unique(['boutique_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['boutique_id', 'slug']);
            $table->dropForeign(['boutique_id']);
            $table->dropColumn('boutique_id');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->unique('slug');
        });
    }
};
