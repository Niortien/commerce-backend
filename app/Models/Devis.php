<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Devis / facture proforma : prix promis à un client, sans mouvement de stock tant qu'il n'est pas converti en vente. */
class Devis extends Model
{
    protected $table = 'devis';
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUTS = ['EN_COURS', 'ACCEPTE', 'CONVERTI', 'ANNULE'];

    protected $fillable = [
        'boutique_id', 'reference', 'client_nom', 'client_telephone', 'statut', 'valable_jusqu_au',
        'total_avant_remise', 'remise_montant', 'total_montant', 'notes', 'sortie_id', 'user_id',
    ];
    protected $casts = [
        'valable_jusqu_au'   => 'date:Y-m-d',
        'total_avant_remise' => 'decimal:2',
        'remise_montant'     => 'decimal:2',
        'total_montant'      => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function lignes(): HasMany { return $this->hasMany(LigneDevis::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function sortie(): BelongsTo { return $this->belongsTo(Sortie::class); }
}
