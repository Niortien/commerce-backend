<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Boutique extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'nom', 'slug', 'type_commerce', 'adresse', 'ville', 'whatsapp', 'email', 'telephone',
        'logo_url', 'is_active', 'statut', 'vitrine_active', 'suspension_cause', 'suspension_motif',
    ];
    protected $casts = ['is_active' => 'boolean', 'vitrine_active' => 'boolean'];


    public const STATUTS_ACTIFS = ['ESSAI', 'ACTIF'];
    public const STATUTS = ['EN_ATTENTE', 'ESSAI', 'ACTIF', 'SUSPENDU', 'ARCHIVE'];

    /** Suspension posée à la fin de l'abonnement : levée automatiquement par un renouvellement ou une prolongation. */
    public const SUSPENSION_ABONNEMENT_EXPIRE = 'ABONNEMENT_EXPIRE';
    /** Suspension décidée par le Super Admin : seul lui la lève. */
    public const SUSPENSION_MANUELLE = 'MANUELLE';

    /**
     * Types de commerce : choisi à l'inscription, modifiable uniquement par le Super Admin.
     * Restaurant, quincaillerie et friperie ont des modules métier ; les autres utilisent les pages standard.
     */
    public const TYPES_COMMERCE = [
        'VETEMENTS', 'RESTAURANT', 'QUINCAILLERIE', 'FRIPERIE',
        'CHAUSSURES', 'ALIMENTATION', 'SUPERMARCHE', 'PHARMACIE', 'ELECTRONIQUE', 'BEAUTE', 'AUTRE',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
        // La suppression d'une boutique et de toutes ses données passe par App\Services\SuppressionBoutique
        // (transaction, ordre compatible avec les clés étrangères, comptes de la boutique compris).
    }

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function variantes(): HasMany { return $this->hasMany(Variante::class); }
    public function entrees(): HasMany { return $this->hasMany(Entree::class); }
    public function sorties(): HasMany { return $this->hasMany(Sortie::class); }
    public function caisseSessions(): HasMany { return $this->hasMany(CaisseSession::class); }
    public function categories(): HasMany { return $this->hasMany(Categorie::class); }
    public function produits(): HasMany { return $this->hasMany(Produit::class); }
    public function fournisseurs(): HasMany { return $this->hasMany(Fournisseur::class); }
    public function abonnements(): HasMany { return $this->hasMany(Abonnement::class); }

    /**
     * Abonnement courant (statut ACTIF), même si sa date de fin est passée : c'est lui qu'on affiche
     * (« Mensuel, expiré le … ») et qu'on prolonge.
     */
    public function abonnementActif(): ?Abonnement
    {
        return $this->abonnements()
            ->where('statut', 'ACTIF')
            ->orderByDesc('date_fin')
            ->first();
    }

    /** Abonnement qui donne réellement accès maintenant : statut ACTIF et date de fin à venir. */
    public function abonnementEnCours(): ?Abonnement
    {
        return $this->abonnements()
            ->where('statut', 'ACTIF')
            ->where('date_fin', '>', now())
            ->orderByDesc('date_fin')
            ->first();
    }

    /** Statut d'accès attendu pour un plan : l'essai garde le statut ESSAI, un plan payant donne ACTIF. */
    public static function statutPourPlan(string $plan): string
    {
        return $plan === 'ESSAI' ? 'ESSAI' : 'ACTIF';
    }

    /** Ouvre l'accès selon le plan et efface toute trace de suspension. */
    public function activerPourPlan(string $plan): void
    {
        $this->update([
            'statut'           => self::statutPourPlan($plan),
            'is_active'        => true,
            'suspension_cause' => null,
            'suspension_motif' => null,
        ]);
    }

    /**
     * Garde le statut d'accès cohérent avec les abonnements, dans les deux sens :
     * - ESSAI/ACTIF sans abonnement en cours → SUSPENDU (cause ABONNEMENT_EXPIRE) ;
     * - SUSPENDU pour expiration alors qu'un abonnement en cours existe à nouveau → accès rouvert.
     * Une suspension MANUELLE, un archivage ou une boutique EN_ATTENTE ne sont jamais modifiés ici :
     * ce sont des décisions du Super Admin.
     *
     * Appelée à la connexion, sur chaque requête métier (EnsureBoutiqueActive), sur /boutiques/me,
     * dans les listes du Super Admin et après chaque modification d'abonnement.
     */
    public function synchroniserStatutAbonnement(): void
    {
        $enCours = $this->abonnementEnCours();

        if (in_array($this->statut, self::STATUTS_ACTIFS, true)) {
            if ($enCours) {
                return;
            }

            $this->update([
                'statut'           => 'SUSPENDU',
                'is_active'        => false,
                'suspension_cause' => self::SUSPENSION_ABONNEMENT_EXPIRE,
                'suspension_motif' => null,
            ]);
            // Les Super Admins sont prévenus (une seule fois), sans ralentir la requête en cours.
            $boutique = $this;
            dispatch(fn () => app(\App\Services\AlertesSuperAdmin::class)->signalerFinSiTerminee($boutique))->afterResponse();
            return;
        }

        if ($this->suspendueParExpiration() && $enCours) {
            $this->activerPourPlan($enCours->plan);
        }
    }

    /** Suspendue automatiquement à la fin de l'abonnement (levée par un renouvellement). */
    public function suspendueParExpiration(): bool
    {
        return $this->statut === 'SUSPENDU' && $this->suspension_cause === self::SUSPENSION_ABONNEMENT_EXPIRE;
    }

    /**
     * Fermée par une décision du Super Admin : suspension manuelle (ou de cause inconnue, par prudence)
     * ou archivage. Un renouvellement ne la rouvre que sur demande explicite.
     */
    public function fermeeParLeSuperAdmin(): bool
    {
        return $this->statut === 'ARCHIVE' || ($this->statut === 'SUSPENDU' && !$this->suspendueParExpiration());
    }

    public function accesAutorise(): bool
    {
        $this->synchroniserStatutAbonnement();
        return in_array($this->statut, self::STATUTS_ACTIFS, true);
    }

    /**
     * Pourquoi l'accès est refusé, en clair pour l'admin et les caissiers de la boutique.
     * Renvoie null si l'accès est ouvert.
     */
    public function messageAccesRefuse(): ?string
    {
        if (in_array($this->statut, self::STATUTS_ACTIFS, true)) {
            return null;
        }

        return match (true) {
            $this->statut === 'EN_ATTENTE' =>
                "Votre boutique attend la confirmation de son abonnement. Contactez le support pour l'activer.",
            $this->statut === 'ARCHIVE' =>
                "Votre boutique est fermée (archivée) sur la plateforme. Contactez le support pour la rouvrir.",
            $this->suspendueParExpiration() => $this->messageExpiration(),
            default =>
                "L'accès de votre boutique a été suspendu par la plateforme"
                . ($this->suspension_motif ? " : {$this->suspension_motif}" : '')
                . ". Contactez le support pour le rétablir.",
        };
    }

    private function messageExpiration(): string
    {
        $dernier = $this->abonnementActif();
        $essai = $dernier?->plan === 'ESSAI';
        $quoi = $essai ? "La période d'essai de votre boutique" : "L'abonnement de votre boutique";
        $date = $dernier ? ' le ' . $dernier->date_fin->format('d/m/Y') : '';

        return "{$quoi} a pris fin{$date} : le stock et la caisse sont bloqués. Contactez le support pour le renouveler.";
    }
}
