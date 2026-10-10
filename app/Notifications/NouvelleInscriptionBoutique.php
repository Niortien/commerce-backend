<?php

namespace App\Notifications;

use App\Models\Boutique;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Prévient les Super Admins qu'un commerçant vient de s'inscrire (essai gratuit démarré). */
class NouvelleInscriptionBoutique extends Notification
{
    public function __construct(private Boutique $boutique, private User $admin) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = $this->boutique;
        $fin = $b->abonnementActif()?->date_fin?->format('d/m/Y');

        return (new MailMessage)
            ->subject("Nouvelle inscription : {$b->nom}")
            ->greeting('Nouvelle boutique inscrite')
            ->line("**{$b->nom}** vient de créer son compte sur Mon Djossi.")
            ->line('Type de commerce : ' . ucfirst(strtolower($b->type_commerce)))
            ->line("Admin : {$this->admin->email}" . ($this->admin->telephone ? " · {$this->admin->telephone}" : ''))
            ->line('Ville : ' . ($b->ville ?: 'non renseignée') . ($b->whatsapp ? " · WhatsApp : {$b->whatsapp}" : ''))
            ->line($fin ? "Essai gratuit jusqu'au {$fin}." : 'Essai gratuit démarré.')
            ->action('Voir la boutique', rtrim(config('app.frontend_url'), '/') . "/super-admin/boutiques/{$b->id}")
            ->salutation('Mon Djossi');
    }
}
