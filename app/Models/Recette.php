<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Une ligne de fiche technique : quantité d'un ingrédient pour une portion d'un plat. */
class Recette extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['plat_id', 'ingredient_variante_id', 'quantite'];
    protected $casts = ['quantite' => 'float'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function plat(): BelongsTo { return $this->belongsTo(Produit::class, 'plat_id'); }
    public function ingredient(): BelongsTo { return $this->belongsTo(Variante::class, 'ingredient_variante_id'); }
}
