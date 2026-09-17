<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic en lecture seule : compte les lignes des tables critiques et
 * affiche l'état des migrations. Sert à comprendre rapidement l'ampleur
 * d'un incident de données sans risquer d'y toucher.
 */
class DbDiagnose extends Command
{
    protected $signature = 'db:diagnose';
    protected $description = 'Compte les lignes des tables clés et affiche la connexion DB active + le dernier lot de migrations';

    public function handle(): int
    {
        $this->info('Connexion active : ' . config('database.default') . ' -> ' . config('database.connections.' . config('database.default') . '.database'));
        $this->newLine();

        $tables = ['users', 'boutiques', 'produits', 'categories', 'variantes', 'abonnements', 'entrees', 'sorties', 'caisse_sessions', 'transactions', 'audit_logs'];
        $rows = [];
        foreach ($tables as $t) {
            try {
                $rows[] = [$t, DB::table($t)->count()];
            } catch (\Throwable $e) {
                $rows[] = [$t, 'ERREUR: ' . $e->getMessage()];
            }
        }
        $this->table(['Table', 'Lignes'], $rows);

        $this->newLine();
        $this->info('Dernières migrations exécutées :');
        $migrations = DB::table('migrations')->orderByDesc('id')->limit(15)->get(['migration', 'batch']);
        $this->table(['Migration', 'Batch'], $migrations->map(fn ($m) => [$m->migration, $m->batch]));

        return self::SUCCESS;
    }
}
