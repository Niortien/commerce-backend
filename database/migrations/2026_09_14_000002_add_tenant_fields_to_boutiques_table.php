<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Transforme "Boutique" en véritable tenant SaaS : identité (slug, contact),
 * et statut d'accès (EN_ATTENTE / ESSAI / ACTIF / SUSPENDU / ARCHIVE) géré
 * par le Super Admin, en plus du booléen is_active déjà existant (conservé
 * pour compatibilité et gardé synchronisé avec le statut).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('nom');
            $table->string('email')->nullable()->after('whatsapp');
            $table->string('telephone')->nullable()->after('email');
            $table->string('logo_url')->nullable()->after('telephone');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE boutiques ADD COLUMN statut ENUM('EN_ATTENTE','ESSAI','ACTIF','SUSPENDU','ARCHIVE') NOT NULL DEFAULT 'ACTIF' AFTER is_active");
        }

        // Backfill statut à partir de is_active
        DB::table('boutiques')->where('is_active', true)->update(['statut' => 'ACTIF']);
        DB::table('boutiques')->where('is_active', false)->update(['statut' => 'ARCHIVE']);

        // Backfill slug unique à partir du nom
        $slugs = [];
        foreach (DB::table('boutiques')->orderBy('created_at')->get(['id', 'nom']) as $boutique) {
            $base = Str::slug($boutique->nom) ?: 'boutique';
            $slug = $base;
            $i = 2;
            while (in_array($slug, $slugs, true)) {
                $slug = "{$base}-{$i}";
                $i++;
            }
            $slugs[] = $slug;
            DB::table('boutiques')->where('id', $boutique->id)->update(['slug' => $slug]);
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE boutiques MODIFY COLUMN slug VARCHAR(255) NOT NULL');
        }
        Schema::table('boutiques', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'email', 'telephone', 'logo_url', 'statut']);
        });
    }
};
