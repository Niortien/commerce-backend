<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory;
    use Notifiable;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['email', 'password_hash', 'role', 'boutique_id'];
    protected $hidden = ['password_hash'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function getJWTIdentifier(): mixed { return $this->getKey(); }

    public function getJWTCustomClaims(): array
    {
        return [
            'email'      => $this->email,
            'role'       => $this->role,
            'boutiqueId' => $this->boutique_id,
        ];
    }

    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function entrees(): HasMany { return $this->hasMany(Entree::class); }
    public function sorties(): HasMany { return $this->hasMany(Sortie::class); }
    public function mouvements(): HasMany { return $this->hasMany(MouvementStock::class); }
    public function caisseSessions(): HasMany { return $this->hasMany(CaisseSession::class); }

    public function sendPasswordResetNotification($token): void
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $url = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($this->email);
        $this->notify(new ResetPasswordNotification($url));
    }
}
