<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Sortie extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'reference', 'type', 'total_avant_remise', 'remise_montant',
        'total_montant', 'notes', 'user_id', 'boutique_id', 'transaction_id',
    ];

    protected $casts = [
        'total_avant_remise' => 'decimal:2',
        'remise_montant'     => 'decimal:2',
        'total_montant'      => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function lignes(): HasMany { return $this->hasMany(LigneSortie::class); }
    public function transaction(): HasOne { return $this->hasOne(Transaction::class); }
}
