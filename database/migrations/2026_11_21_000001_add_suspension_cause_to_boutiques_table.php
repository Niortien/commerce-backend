<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue les deux causes du statut SUSPENDU d'une boutique :
 * - ABONNEMENT_EXPIRE : posée automatiquement à la fin de l'abonnement ou de l'essai, levée
 *   automatiquement dès qu'un abonnement en cours existe à nouveau (renouvellement, prolongation) ;
 * - MANUELLE : décidée par le Super Admin (avec un motif), levée seulement par lui.
 *
 * Avant cette colonne, les deux cas étaient confondus : une boutique suspendue à l'expiration
 * n'était plus jamais réactivée par un renouvellement (traitée comme une suspension manuelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('suspension_cause', 20)->nullable()->after('is_active');
            $table->string('suspension_motif', 255)->nullable()->after('suspension_cause');
        });

        if (!Schema::hasColumn('boutiques', 'statut')) {
            return;
        }

        // Boutiques déjà suspendues : manuelle si la dernière décision de statut du Super Admin
        // était une suspension, sinon c'est l'expiration automatique de l'abonnement.
        foreach (DB::table('boutiques')->where('statut', 'SUSPENDU')->pluck('id') as $boutiqueId) {
            $derniere = DB::table('audit_logs')
                ->where('action', 'BOUTIQUE_STATUT_CHANGE')
                ->where('entity_id', $boutiqueId)
                ->orderByDesc('created_at')
                ->value('description');

            // Description écrite par SuperAdminBoutiqueController::changerStatut : « Statut de X changé en SUSPENDU (motif) ».
            $manuelle = is_string($derniere) && preg_match('/ en SUSPENDU(\s|$)/u', $derniere) === 1;

            DB::table('boutiques')->where('id', $boutiqueId)->update([
                'suspension_cause' => $manuelle ? 'MANUELLE' : 'ABONNEMENT_EXPIRE',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn(['suspension_cause', 'suspension_motif']);
        });
    }
};
