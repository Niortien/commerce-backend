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

    protected $fillable = ['email', 'telephone', 'password_hash', 'role', 'boutique_id'];
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

    public function isSuperAdmin(): bool { return $this->role === 'SUPER_ADMIN'; }
    public function isAdmin(): bool { return $this->role === 'ADMIN'; }
    public function isCaissier(): bool { return $this->role === 'CAISSIER'; }

    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function entrees(): HasMany { return $this->hasMany(Entree::class); }
    public function sorties(): HasMany { return $this->hasMany(Sortie::class); }
    public function mouvements(): HasMany { return $this->hasMany(MouvementStock::class); }
    public function caisseSessions(): HasMany { return $this->hasMany(CaisseSession::class); }

    /**
     * Le compte a-t-il des opérations qui en dépendent (clés étrangères en RESTRICT) ?
     * Un tel compte ne peut pas être supprimé seul : l'historique de la boutique le référence.
     */
    public function aDesOperations(): bool
    {
        foreach (['sorties', 'entrees', 'caisse_sessions', 'mouvement_stocks', 'balles', 'devis', 'operations_credit'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)
                && \Illuminate\Support\Facades\DB::table($table)->where('user_id', $this->id)->exists()) {
                return true;
            }
        }
        return false;
    }

    public function sendPasswordResetNotification($token): void
    {
        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $url = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($this->email);
        $this->notify(new ResetPasswordNotification($url));
    }
}
