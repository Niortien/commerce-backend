<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CaisseSession extends Model
{
    protected $table = 'caisse_sessions';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'boutique_id', 'date_ouverture', 'date_fermeture',
        'montant_ouverture', 'montant_fermeture', 'montant_theorique', 'ecart', 'statut',
    ];

    protected $casts = [
        'montant_ouverture'  => 'decimal:2',
        'montant_fermeture'  => 'decimal:2',
        'montant_theorique'  => 'decimal:2',
        'ecart'              => 'decimal:2',
        'date_ouverture'     => 'datetime',
        'date_fermeture'     => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class, 'session_id'); }
}
