<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LigneEntree extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['entree_id', 'variante_id', 'quantite', 'prix_unitaire'];
    protected $casts = ['prix_unitaire' => 'decimal:2'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function entree(): BelongsTo { return $this->belongsTo(Entree::class); }
    public function variante(): BelongsTo { return $this->belongsTo(Variante::class); }
}
