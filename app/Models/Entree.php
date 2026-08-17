<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Entree extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['reference', 'fournisseur', 'fournisseur_id', 'total_cout', 'notes', 'user_id', 'boutique_id'];
    protected $casts = ['total_cout' => 'decimal:2'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function lignes(): HasMany { return $this->hasMany(LigneEntree::class); }
    public function fournisseurModel(): BelongsTo { return $this->belongsTo(Fournisseur::class, 'fournisseur_id'); }
}
