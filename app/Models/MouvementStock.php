<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MouvementStock extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'variante_id', 'type', 'quantite', 'motif',
        'reference_entree', 'reference_sortie', 'user_id',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function variante(): BelongsTo { return $this->belongsTo(Variante::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
