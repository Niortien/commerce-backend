<?php

namespace App\Notifications;

use App\Models\Abonnement;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Prévient les Super Admins qu'un abonnement (ou un essai) est arrivé à son terme : la boutique est bloquée. */
class AbonnementTermine extends Notification
{
    public function __construct(private Abonnement $abonnement) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->abonnement;
        $b = $a->boutique;
        $essai = $a->plan === 'ESSAI';
        $admin = $b->users()->where('role', 'ADMIN')->orderBy('created_at')->first();

        return (new MailMessage)
            ->subject(($essai ? 'Essai terminé' : 'Abonnement terminé') . " : {$b->nom}")
            ->greeting($essai ? "Fin d'essai gratuit" : "Fin d'abonnement")
            ->line("L'" . ($essai ? 'essai gratuit' : "abonnement {$a->plan}") . " de **{$b->nom}** s'est terminé le {$a->date_fin->format('d/m/Y')}.")
            ->line('La boutique est maintenant bloquée : plus de ventes ni de mouvements de stock tant que l\'abonnement n\'est pas renouvelé.')
            ->line('Admin : ' . ($admin?->email ?? 'inconnu') . ($admin?->telephone ? " · {$admin->telephone}" : '') . ($b->whatsapp ? " · WhatsApp : {$b->whatsapp}" : ''))
            ->action('Renouveler l\'abonnement', rtrim(config('app.frontend_url'), '/') . "/super-admin/boutiques/{$b->id}")
            ->salutation('Mon Djossi');
    }
}
