<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Abonnement extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'boutique_id', 'plan', 'statut', 'date_debut', 'date_fin',
        'montant', 'devise', 'notes', 'cree_par_id',
    ];

    protected $casts = [
        'date_debut' => 'datetime',
        'date_fin'   => 'datetime',
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
}
