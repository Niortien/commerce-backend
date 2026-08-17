<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Transaction extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    protected $fillable = ['session_id', 'sortie_id', 'montant', 'mode_paiement', 'reference', 'notes'];
    protected $casts = ['montant' => 'decimal:2'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function session(): BelongsTo { return $this->belongsTo(CaisseSession::class, 'session_id'); }
    public function sortie(): BelongsTo { return $this->belongsTo(Sortie::class); }
}
