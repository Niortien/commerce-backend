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

    protected $fillable = [
        'nom', 'slug', 'adresse', 'ville', 'whatsapp', 'email', 'telephone',
        'logo_url', 'is_active', 'statut',
    ];
    protected $casts = ['is_active' => 'boolean'];

    public const STATUTS_ACTIFS = ['ESSAI', 'ACTIF'];

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
    public function categories(): HasMany { return $this->hasMany(Categorie::class); }
    public function produits(): HasMany { return $this->hasMany(Produit::class); }
    public function fournisseurs(): HasMany { return $this->hasMany(Fournisseur::class); }
    public function abonnements(): HasMany { return $this->hasMany(Abonnement::class); }

    public function abonnementActif(): ?Abonnement
    {
        return $this->abonnements()
            ->where('statut', 'ACTIF')
            ->orderByDesc('date_fin')
            ->first();
    }

    /**
     * Recalcule le statut de la boutique en fonction de son abonnement en
     * cours (auto-expiration) et garde is_active synchronisé. À appeler à
     * la connexion et sur les requêtes protégées (voir EnsureBoutiqueActive).
     */
    public function synchroniserStatutAbonnement(): void
    {
        if (in_array($this->statut, ['SUSPENDU', 'ARCHIVE', 'EN_ATTENTE'], true)) {
            return; // décisions manuelles du Super Admin, on ne les écrase pas
        }

        $abonnement = $this->abonnementActif();
        $expire = !$abonnement || $abonnement->date_fin->isPast();

        if ($expire && $this->statut !== 'SUSPENDU') {
            $this->update(['statut' => 'SUSPENDU', 'is_active' => false]);
        }
    }

    public function accesAutorise(): bool
    {
        $this->synchroniserStatutAbonnement();
        return in_array($this->statut, self::STATUTS_ACTIFS, true);
    }
}
