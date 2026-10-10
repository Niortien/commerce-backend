<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Friperie : lot acheté en bloc, déballé pièce par pièce. Son coût se répartit sur ses pièces. */
class Balle extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUTS = ['EN_COURS', 'TERMINEE'];

    protected $fillable = [
        'boutique_id', 'numero', 'libelle', 'fournisseur', 'fournisseur_id', 'cout_achat', 'frais',
        'prix_choix_1', 'prix_choix_2', 'prix_choix_3',
        'date_achat', 'statut', 'entree_id', 'notes', 'user_id',
    ];
    protected $casts = [
        'numero'     => 'integer',
        'cout_achat' => 'decimal:2',
        'frais'      => 'decimal:2',
        'prix_choix_1' => 'decimal:2',
        'prix_choix_2' => 'decimal:2',
        'prix_choix_3' => 'decimal:2',
        'date_achat' => 'date:Y-m-d',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function pieces(): HasMany { return $this->hasMany(Produit::class); }
    public function entree(): BelongsTo { return $this->belongsTo(Entree::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** Achat + frais (transport, dédouanement), en chaîne décimale exacte. */
    public function coutTotal(): string
    {
        return bcadd((string) $this->cout_achat, (string) $this->frais, 2);
    }
}
