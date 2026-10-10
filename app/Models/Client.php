<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Client professionnel qui peut acheter à crédit (quincaillerie). */
class Client extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['boutique_id', 'nom', 'telephone', 'plafond_credit', 'notes', 'is_actif'];
    protected $casts = [
        'plafond_credit' => 'decimal:2',
        'is_actif'       => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function operations(): HasMany { return $this->hasMany(OperationCredit::class); }
}
