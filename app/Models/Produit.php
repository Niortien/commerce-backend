<?php

namespace App\Models;

use App\Services\CloudinaryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Produit extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'boutique_id', 'nom', 'sku', 'description', 'categorie_id',
        'prix_vente', 'prix_achat', 'image_url',
        'is_actif', 'en_promo', 'prix_promo', 'date_debut_promo', 'date_fin_promo',
        'unite', 'nature', 'conditionnement_unite', 'conditionnement_quantite',
        'balle_id', 'numero_piece', 'piece_unique', 'choix', 'prix_initial', 'derniere_demarque_at', 'nb_demarques',
    ];

    /** Friperie : qualité d'une pièce, du 1er (la plus belle) au 3e choix. */
    public const CHOIX = [1, 2, 3];

    /** Unités de vente. Les premières se comptent (quantité entière), les autres se mesurent. */
    public const UNITES = ['PIECE', 'PORTION', 'SAC', 'CARTON', 'BOITE', 'PAQUET', 'BOUTEILLE', 'KG', 'G', 'L', 'M', 'M2'];
    public const UNITES_ENTIERES = ['PIECE', 'PORTION', 'SAC', 'CARTON', 'BOITE', 'PAQUET', 'BOUTEILLE'];

    /** ARTICLE : vendu tel quel. PLAT : préparé à partir d'ingrédients. INGREDIENT : acheté, jamais vendu seul. */
    public const NATURES = ['ARTICLE', 'PLAT', 'INGREDIENT'];

    protected $casts = [
        'prix_vente'       => 'decimal:2',
        'prix_achat'       => 'decimal:2',
        'prix_promo'       => 'decimal:2',
        'conditionnement_quantite' => 'float',
        'is_actif'         => 'boolean',
        'en_promo'         => 'boolean',
        'piece_unique'     => 'boolean',
        'numero_piece'     => 'integer',
        'choix'            => 'integer',
        'prix_initial'     => 'decimal:2',
        'derniere_demarque_at' => 'datetime',
        'nb_demarques'     => 'integer',
        'date_debut_promo' => 'datetime',
        'date_fin_promo'   => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());

        static::deleting(function (Produit $produit) {
            $cloudinary = app(CloudinaryService::class);

            // Supprimer les images Cloudinary + enregistrements
            foreach ($produit->images as $image) {
                $cloudinary->deleteByUrl($image->url);
            }
            $produit->images()->delete();

            // Supprimer l'image principale si hébergée sur Cloudinary
            if ($produit->image_url && str_contains($produit->image_url, 'cloudinary')) {
                $cloudinary->deleteByUrl($produit->image_url);
            }

            // Supprimer les variantes (cascade : lignes entree/sortie + mouvements)
            $produit->variantes->each->delete();
        });
    }

    public function boutique(): BelongsTo { return $this->belongsTo(Boutique::class); }
    public function categorie(): BelongsTo { return $this->belongsTo(Categorie::class); }

    /** Friperie : balle dont la pièce est sortie au déballage. */
    public function balle(): BelongsTo { return $this->belongsTo(Balle::class); }

    /** Fiche technique d'un plat : ingrédients pour une portion. */
    public function recette(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Recette::class, 'plat_id'); }

    /** Une quantité de ce produit doit-elle être entière (pièces, sacs…) ou peut-elle être décimale (kg, m…) ? */
    public function seVendALUnite(): bool
    {
        return in_array($this->unite ?? 'PIECE', self::UNITES_ENTIERES, true);
    }
    public function variantes(): HasMany { return $this->hasMany(Variante::class); }
    public function images(): HasMany { return $this->hasMany(ProduitImage::class); }
}
