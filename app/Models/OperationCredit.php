<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Ligne du compte d'un client : vente à crédit, règlement ou annulation de vente. */
class OperationCredit extends Model
{
    protected $table = 'operations_credit';
    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPES = ['VENTE', 'REGLEMENT', 'ANNULATION'];

    /** Une vente et son acompte partagent la même seconde : la vente passe d'abord dans le relevé. */
    public const ORDRE_A_HEURE_EGALE = "CASE type WHEN 'VENTE' THEN 0 WHEN 'ANNULATION' THEN 1 ELSE 2 END";

    protected $fillable = [
        'boutique_id', 'client_id', 'type', 'montant', 'sortie_id', 'transaction_id',
        'mode_paiement', 'echeance', 'notes', 'user_id',
    ];
    protected $casts = [
        'montant'  => 'decimal:2',
        'echeance' => 'date:Y-m-d',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function sortie(): BelongsTo { return $this->belongsTo(Sortie::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
