<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rend boutique_id obligatoire sur toutes les tables opérationnelles :
 * chaque variante, entrée, sortie et session de caisse appartient désormais
 * strictement à une seule boutique-locataire (fin du "catalogue global").
 */
return new class extends Migration
{
    private function fallbackBoutiqueId(): ?string
    {
        return DB::table('boutiques')->orderBy('created_at')->value('id');
    }

    public function up(): void
    {
        $fallback = $this->fallbackBoutiqueId();

        // variantes : hériter de la boutique du produit
        DB::statement('
            UPDATE variantes v
            JOIN produits p ON p.id = v.produit_id
            SET v.boutique_id = p.boutique_id
            WHERE v.boutique_id IS NULL OR v.boutique_id <> p.boutique_id
        ');

        // entrees / sorties / caisse_sessions : hériter de la boutique de l'utilisateur créateur
        foreach (['entrees', 'sorties', 'caisse_sessions'] as $table) {
            DB::statement("
                UPDATE {$table} t
                JOIN users u ON u.id = t.user_id
                SET t.boutique_id = u.boutique_id
                WHERE t.boutique_id IS NULL AND u.boutique_id IS NOT NULL
            ");
            if ($fallback) {
                DB::table($table)->whereNull('boutique_id')->update(['boutique_id' => $fallback]);
            }
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE variantes MODIFY COLUMN boutique_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE entrees MODIFY COLUMN boutique_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE sorties MODIFY COLUMN boutique_id CHAR(36) NOT NULL');
            DB::statement('ALTER TABLE caisse_sessions MODIFY COLUMN boutique_id CHAR(36) NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement('ALTER TABLE variantes MODIFY COLUMN boutique_id CHAR(36) NULL');
        DB::statement('ALTER TABLE entrees MODIFY COLUMN boutique_id CHAR(36) NULL');
        DB::statement('ALTER TABLE sorties MODIFY COLUMN boutique_id CHAR(36) NULL');
        DB::statement('ALTER TABLE caisse_sessions MODIFY COLUMN boutique_id CHAR(36) NULL');
    }
};
