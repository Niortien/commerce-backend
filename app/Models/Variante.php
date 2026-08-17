<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Variante extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['produit_id', 'boutique_id', 'taille', 'couleur', 'quantite_stock', 'seuil_alerte'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());

        static::deleting(function (Variante $variante) {
            $variante->lignesEntree()->delete();
            $variante->lignesSortie()->delete();
            $variante->mouvements()->delete();
        });
    }

    public function produit(): BelongsTo { return $this->belongsTo(Produit::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function mouvements(): HasMany { return $this->hasMany(MouvementStock::class); }
    public function lignesEntree(): HasMany { return $this->hasMany(LigneEntree::class); }
    public function lignesSortie(): HasMany { return $this->hasMany(LigneSortie::class); }
}
