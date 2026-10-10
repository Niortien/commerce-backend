<?php

namespace App\Services;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\User;
use App\Notifications\AbonnementTermine;
use App\Notifications\NouvelleInscriptionBoutique;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * Mails envoyés aux Super Admins : nouvelle inscription, fin d'abonnement (ou d'essai).
 * Un mail qui ne part pas (SMTP absent ou en panne) ne bloque jamais l'inscription ni la caisse : on le note dans les logs.
 */
class AlertesSuperAdmin
{
    public function nouvelleInscription(Boutique $boutique, User $admin): void
    {
        $this->envoyer(new NouvelleInscriptionBoutique($boutique, $admin), "inscription {$boutique->nom}");
    }

    /**
     * Prévient une seule fois par abonnement terminé. Renvoie true si le mail est parti.
     * Le repère est posé avant l'envoi : deux requêtes simultanées n'envoient pas deux mails.
     */
    public function finAbonnement(Abonnement $abonnement): bool
    {
        $pris = Abonnement::whereKey($abonnement->id)->whereNull('fin_signalee_at')->update(['fin_signalee_at' => now()]);
        if ($pris === 0) return false;

        return $this->envoyer(new AbonnementTermine($abonnement->loadMissing('boutique')), "fin d'abonnement {$abonnement->boutique_id}");
    }

    /** Dernier abonnement terminé de la boutique, s'il n'a pas encore été signalé et qu'aucun autre ne prend le relais. */
    public function signalerFinSiTerminee(Boutique $boutique): bool
    {
        $enCours = $boutique->abonnements()->where('statut', 'ACTIF')->where('date_fin', '>', now())->exists();
        if ($enCours) return false;

        $dernier = $boutique->abonnements()
            ->where('statut', 'ACTIF')
            ->where('date_fin', '<=', now())
            ->whereNull('fin_signalee_at')
            ->orderByDesc('date_fin')
            ->first();

        return $dernier ? $this->finAbonnement($dernier) : false;
    }

    /** Passage quotidien : toutes les boutiques dont l'abonnement vient de se terminer, même si personne ne s'y connecte. */
    public function signalerFinsTerminees(): int
    {
        $n = 0;
        $boutiqueIds = Abonnement::where('statut', 'ACTIF')
            ->where('date_fin', '<=', now())
            ->whereNull('fin_signalee_at')
            ->distinct()
            ->pluck('boutique_id');

        Boutique::whereIn('id', $boutiqueIds)->where('statut', '!=', 'ARCHIVE')->each(function (Boutique $b) use (&$n) {
            if ($this->signalerFinSiTerminee($b)) $n++;
        });

        return $n;
    }

    private function envoyer(Notification $notification, string $contexte): bool
    {
        $superAdmins = User::where('role', 'SUPER_ADMIN')->whereNotNull('email')->get();
        if ($superAdmins->isEmpty()) return false;

        try {
            Notifier::send($superAdmins, $notification);
            return true;
        } catch (\Throwable $e) {
            Log::warning("Mail aux Super Admins non envoyé ({$contexte}) : {$e->getMessage()}");
            return false;
        }
    }
}
