<?php

namespace Database\Seeders;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\User;
use App\Services\CategoriesDeDepart;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Comptes et boutiques FICTIFS pour tester en local les actions du Super Admin
 * (suspension, abonnements, essai expiré, suppression) et leur effet côté boutique.
 *
 *   APP_ENV=localtest php artisan db:seed --class=LocalTestFixturesSeeder
 *
 * Refuse de tourner hors d'une base locale (hôte 127.0.0.1/localhost, environnement local/localtest/testing) :
 * ces mots de passe de test ne doivent jamais arriver sur la base de production.
 * Idempotent : relancé, il remet les trois boutiques TEST dans leur état de départ (statut, abonnement).
 * Les produits, entrées et ventes sont ajoutés par les vrais parcours de l'API (voir le rapport de test).
 */
class LocalTestFixturesSeeder extends Seeder
{
    /** Mot de passe commun aux comptes de test (local uniquement). */
    public const MOT_DE_PASSE = 'TestLocal-2026!';

    public const SUPER_ADMIN = 'superadmin@habit-test.example';

    /** @var list<array{nom: string, slug: string, type: string, plan: string, jours: int, admin: string, caissier: string}> */
    public const BOUTIQUES = [
        ['nom' => 'TEST - Alpha Streetwear', 'slug' => 'test-alpha-streetwear', 'type' => 'VETEMENTS', 'plan' => 'MENSUEL', 'jours' => 30,
         'admin' => 'alpha.admin@habit-test.example', 'caissier' => 'alpha.caisse@habit-test.example'],
        ['nom' => 'TEST - Beta Sneakers', 'slug' => 'test-beta-sneakers', 'type' => 'CHAUSSURES', 'plan' => 'TRIMESTRIEL', 'jours' => 90,
         'admin' => 'beta.admin@habit-test.example', 'caissier' => 'beta.caisse@habit-test.example'],
        ['nom' => 'TEST - Gamma Essai 7 jours', 'slug' => 'test-gamma-essai', 'type' => 'VETEMENTS', 'plan' => 'ESSAI', 'jours' => 7,
         'admin' => 'gamma.admin@habit-test.example', 'caissier' => 'gamma.caisse@habit-test.example'],
    ];

    public function run(): void
    {
        $this->verifierBaseLocale();

        $superAdmin = User::updateOrCreate(
            ['email' => self::SUPER_ADMIN],
            ['password_hash' => Hash::make(self::MOT_DE_PASSE), 'role' => 'SUPER_ADMIN', 'boutique_id' => null]
        );

        foreach (self::BOUTIQUES as $def) {
            DB::transaction(function () use ($def, $superAdmin) {
                $boutique = Boutique::firstOrNew(['slug' => $def['slug']]);
                $boutique->fill([
                    'nom'              => $def['nom'],
                    'type_commerce'    => $def['type'],
                    'ville'            => 'Abidjan',
                    'is_active'        => true,
                    'statut'           => Boutique::statutPourPlan($def['plan']),
                    'suspension_cause' => null,
                    'suspension_motif' => null,
                ])->save();

                foreach (['admin' => 'ADMIN', 'caissier' => 'CAISSIER'] as $cle => $role) {
                    User::updateOrCreate(
                        ['email' => $def[$cle]],
                        ['password_hash' => Hash::make(self::MOT_DE_PASSE), 'role' => $role, 'boutique_id' => $boutique->id]
                    );
                }

                app(CategoriesDeDepart::class)->creer($boutique);

                $boutique->abonnements()->where('statut', 'ACTIF')->update(['statut' => 'EXPIRE']);
                Abonnement::create([
                    'boutique_id' => $boutique->id,
                    'plan'        => $def['plan'],
                    'statut'      => 'ACTIF',
                    'date_debut'  => now(),
                    'date_fin'    => now()->addDays($def['jours'])->endOfDay(),
                    'montant'     => Abonnement::TARIFS_FCFA[$def['plan']],
                    'notes'       => 'Boutique de test locale (LocalTestFixturesSeeder).',
                    'cree_par_id' => $superAdmin->id,
                ]);
            });
        }

        $this->command?->info('Super Admin et 3 boutiques TEST prêts (mot de passe : LocalTestFixturesSeeder::MOT_DE_PASSE).');
    }

    private function verifierBaseLocale(): void
    {
        $connexion = config('database.default');
        $hote = (string) config("database.connections.{$connexion}.host");
        $env = app()->environment();

        $locale = $connexion === 'sqlite' || in_array($hote, ['127.0.0.1', 'localhost', '::1'], true);
        if (!$locale || !in_array($env, ['local', 'localtest', 'testing'], true)) {
            throw new RuntimeException("LocalTestFixturesSeeder refusé : base « {$connexion}@{$hote} » / environnement « {$env} » non locaux.");
        }
    }
}
