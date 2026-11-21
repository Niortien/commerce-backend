<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Abonnement extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    public const PLANS = ['ESSAI', 'MENSUEL', 'TRIMESTRIEL', 'ANNUEL'];
    public const STATUTS = ['ACTIF', 'EXPIRE', 'SUSPENDU', 'ANNULE'];

    /**
     * Tarifs publics en FCFA (miroir de lib/pricing.ts côté front) : mensuel 5 000 F,
     * trimestriel = 3 mois −10 %, annuel = 12 mois −20 %. Un abonnement enregistré sans montant
     * reçoit le tarif de son plan.
     */
    public const TARIFS_FCFA = ['ESSAI' => 0, 'MENSUEL' => 5000, 'TRIMESTRIEL' => 13500, 'ANNUEL' => 48000];

    /** Durée de l'essai gratuit (jours), pour l'inscription en ligne et l'inscription par le Super Admin. */
    public const DUREE_ESSAI_JOURS = 14;

    /** Durée par défaut d'un plan payant quand le Super Admin n'en précise pas (jours). */
    public const DUREE_PLAN_JOURS = ['ESSAI' => self::DUREE_ESSAI_JOURS, 'MENSUEL' => 30, 'TRIMESTRIEL' => 90, 'ANNUEL' => 365];

    protected $fillable = [
        'boutique_id', 'plan', 'statut', 'date_debut', 'date_fin',
        'montant', 'devise', 'notes', 'cree_par_id',
    ];

    protected $casts = [
        'date_debut' => 'datetime',
        'date_fin'   => 'datetime',
        'fin_signalee_at' => 'datetime',
        'montant'    => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = (string) Str::uuid());
    }

    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function creePar(): BelongsTo { return $this->belongsTo(User::class, 'cree_par_id'); }

    public function estEnCours(): bool
    {
        return $this->statut === 'ACTIF' && $this->date_fin->isFuture();
    }

    /**
     * Date de fin saisie par le Super Admin. Une date sans heure (« 2026-12-21 ») vaut jusqu'à la fin
     * de ce jour-là : la boutique garde l'accès toute la journée affichée « jusqu'au 21/12 ».
     */
    public static function dateFinSaisie(string $valeur): Carbon
    {
        $date = Carbon::parse($valeur);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($valeur)) ? $date->endOfDay() : $date;
    }
}
