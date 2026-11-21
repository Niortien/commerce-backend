<?php

namespace App\Services;

use App\Models\Boutique;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression définitive d'une boutique et de TOUTES ses données, comptes compris, dans une seule
 * transaction : si une étape échoue, rien n'est supprimé.
 *
 * L'ordre suit les clés étrangères (plusieurs sont en RESTRICT : produits → catégories,
 * ventes/entrées/sessions/mouvements → utilisateurs), au lieu de compter sur les cascades dont
 * MySQL ne garantit pas l'ordre. Les adresses des images (produits, logo) sont rendues pour être
 * retirées de Cloudinary une fois la transaction validée.
 */
class SuppressionBoutique
{
    public function __construct(private CloudinaryService $cloudinary) {}

    /**
     * @return array{supprime: array<string, int>, images: list<string>}
     */
    public function supprimer(Boutique $boutique): array
    {
        $resultat = DB::transaction(fn () => $this->supprimerDonnees($boutique->id));

        foreach ($resultat['images'] as $url) {
            $this->cloudinary->deleteByUrl($url);
        }

        return $resultat;
    }

    /** Ce qui serait supprimé, sans rien toucher (affiché avant confirmation). */
    public function inventaire(Boutique $boutique): array
    {
        $id = $boutique->id;
        return [
            'produits'        => DB::table('produits')->where('boutique_id', $id)->count(),
            'variantes'       => DB::table('variantes')->where('boutique_id', $id)->count(),
            'entrees'         => DB::table('entrees')->where('boutique_id', $id)->count(),
            'sorties'         => DB::table('sorties')->where('boutique_id', $id)->count(),
            'sessionsCaisse'  => DB::table('caisse_sessions')->where('boutique_id', $id)->count(),
            'utilisateurs'    => DB::table('users')->where('boutique_id', $id)->count(),
            'abonnements'     => DB::table('abonnements')->where('boutique_id', $id)->count(),
        ];
    }

    /**
     * @return array{supprime: array<string, int>, images: list<string>}
     */
    private function supprimerDonnees(string $id): array
    {
        $ids = fn (string $table, string $colonne = 'boutique_id'): Collection
            => DB::table($table)->where($colonne, $id)->pluck('id');

        $userIds     = $ids('users');
        $produitIds  = $ids('produits');
        $sortieIds   = $ids('sorties');
        $entreeIds   = $ids('entrees');
        $sessionIds  = $ids('caisse_sessions');
        $devisIds    = $this->siTable('devis', fn () => $ids('devis'));
        $varianteIds = DB::table('variantes')
            ->where('boutique_id', $id)
            ->orWhereIn('produit_id', $produitIds)
            ->pluck('id');
        $emails = DB::table('users')->whereIn('id', $userIds)->pluck('email');

        $images = DB::table('produit_images')->whereIn('produit_id', $produitIds)->pluck('url')
            ->push(DB::table('boutiques')->where('id', $id)->value('logo_url'))
            ->filter(fn ($url) => is_string($url) && str_contains($url, 'res.cloudinary.com'))
            ->values()
            ->all();

        $n = [];
        $supprimer = function (string $cle, string $table, \Closure $filtre) use (&$n): void {
            if (!Schema::hasTable($table)) return;
            $n[$cle] = ($n[$cle] ?? 0) + $filtre(DB::table($table))->delete();
        };

        // Crédit client (référence clients, ventes, transactions et utilisateurs)
        $supprimer('operationsCredit', 'operations_credit', fn ($q) => $q->where('boutique_id', $id));
        // Encaissements : par session de caisse et par vente
        $supprimer('transactions', 'transactions', fn ($q) => $q->whereIn('session_id', $sessionIds)->orWhereIn('sortie_id', $sortieIds));
        // Devis (quincaillerie)
        $supprimer('lignesDevis', 'ligne_devis', fn ($q) => $q->whereIn('devis_id', $devisIds)->orWhereIn('variante_id', $varianteIds));
        $supprimer('devis', 'devis', fn ($q) => $q->where('boutique_id', $id));
        // Ventes et sorties
        $supprimer('lignesSorties', 'ligne_sorties', fn ($q) => $q->whereIn('sortie_id', $sortieIds)->orWhereIn('variante_id', $varianteIds));
        $supprimer('sorties', 'sorties', fn ($q) => $q->where('boutique_id', $id));
        $supprimer('sessionsCaisse', 'caisse_sessions', fn ($q) => $q->where('boutique_id', $id));
        // Stock
        $supprimer('mouvementsStock', 'mouvement_stocks', fn ($q) => $q->whereIn('variante_id', $varianteIds)->orWhereIn('user_id', $userIds));
        $supprimer('lignesEntrees', 'ligne_entrees', fn ($q) => $q->whereIn('entree_id', $entreeIds)->orWhereIn('variante_id', $varianteIds));
        $supprimer('recettes', 'recettes', fn ($q) => $q->whereIn('plat_id', $produitIds)->orWhereIn('ingredient_variante_id', $varianteIds));
        $supprimer('variantes', 'variantes', fn ($q) => $q->whereIn('id', $varianteIds));
        $supprimer('imagesProduits', 'produit_images', fn ($q) => $q->whereIn('produit_id', $produitIds));
        $supprimer('produits', 'produits', fn ($q) => $q->where('boutique_id', $id));
        // Friperie : balles (après les produits qui y renvoient), puis entrées
        $supprimer('balles', 'balles', fn ($q) => $q->where('boutique_id', $id));
        $supprimer('entrees', 'entrees', fn ($q) => $q->where('boutique_id', $id));
        // Référentiels de la boutique
        $supprimer('categories', 'categories', fn ($q) => $q->where('boutique_id', $id));
        $supprimer('fournisseurs', 'fournisseurs', fn ($q) => $q->where('boutique_id', $id));
        $supprimer('clients', 'clients', fn ($q) => $q->where('boutique_id', $id));
        $supprimer('abonnements', 'abonnements', fn ($q) => $q->where('boutique_id', $id));
        // Comptes de la boutique : messagerie, journal de leurs propres actions, jetons de réinitialisation
        $conversationIds = Schema::hasTable('chat_conversations')
            ? DB::table('chat_conversations')->whereIn('admin_id', $userIds)->pluck('id')
            : collect();
        $supprimer('messages', 'chat_messages', fn ($q) => $q->whereIn('conversation_id', $conversationIds)->orWhereIn('sender_id', $userIds));
        $supprimer('conversations', 'chat_conversations', fn ($q) => $q->whereIn('id', $conversationIds));
        $supprimer('journal', 'audit_logs', fn ($q) => $q->whereIn('user_id', $userIds));
        $supprimer('jetonsMotDePasse', 'password_reset_tokens', fn ($q) => $q->whereIn('email', $emails));
        $supprimer('utilisateurs', 'users', fn ($q) => $q->whereIn('id', $userIds));

        $supprimer('boutique', 'boutiques', fn ($q) => $q->where('id', $id));

        return ['supprime' => $n, 'images' => $images];
    }

    private function siTable(string $table, \Closure $valeur): Collection
    {
        return Schema::hasTable($table) ? $valeur() : collect();
    }
}
