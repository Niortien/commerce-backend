<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LigneDevis extends Model
{
    protected $table = 'ligne_devis';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['devis_id', 'variante_id', 'designation', 'quantite', 'prix_unitaire'];
    protected $casts = ['quantite' => 'float', 'prix_unitaire' => 'decimal:2'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function devis(): BelongsTo { return $this->belongsTo(Devis::class); }
    public function variante(): BelongsTo { return $this->belongsTo(Variante::class); }
}
