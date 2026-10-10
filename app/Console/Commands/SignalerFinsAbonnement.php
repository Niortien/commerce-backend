<?php

namespace App\Console\Commands;

use App\Services\AlertesSuperAdmin;
use Illuminate\Console\Command;

/** Prévient les Super Admins des abonnements et essais terminés (lancée chaque jour par le planificateur). */
class SignalerFinsAbonnement extends Command
{
    protected $signature = 'abonnements:signaler-fins';
    protected $description = "Envoie aux Super Admins un mail pour chaque abonnement terminé et pas encore signalé";

    public function handle(AlertesSuperAdmin $alertes): int
    {
        $n = $alertes->signalerFinsTerminees();
        $this->info("{$n} fin(s) d'abonnement signalée(s).");
        return self::SUCCESS;
    }
}
