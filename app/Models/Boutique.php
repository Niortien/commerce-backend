<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Boutique extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['nom', 'adresse', 'ville', 'whatsapp', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());

        static::deleting(function (Boutique $boutique) {
            // Détacher les utilisateurs sans les supprimer
            User::where('boutique_id', $boutique->id)->update(['boutique_id' => null]);

            // Variantes → cascade (lignes entree/sortie + mouvements)
            $boutique->variantes->each->delete();

            // Entrees → lignes (déjà supprimées par variantes, nettoyage des orphelines)
            $boutique->entrees->each(function (Entree $entree) {
                $entree->lignes()->delete();
                $entree->delete();
            });

            // Sorties → lignes + transaction liée
            $boutique->sorties->each(function (Sortie $sortie) {
                $sortie->lignes()->delete();
                $sortie->transaction()->delete();
                $sortie->delete();
            });

            // Sessions caisse → transactions
            $boutique->caisseSessions->each(function (CaisseSession $session) {
                $session->transactions()->delete();
                $session->delete();
            });
        });
    }

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function variantes(): HasMany { return $this->hasMany(Variante::class); }
    public function entrees(): HasMany { return $this->hasMany(Entree::class); }
    public function sorties(): HasMany { return $this->hasMany(Sortie::class); }
    public function caisseSessions(): HasMany { return $this->hasMany(CaisseSession::class); }
}
