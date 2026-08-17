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
        'nom', 'sku', 'description', 'categorie_id',
        'prix_vente', 'prix_achat', 'image_url',
        'is_actif', 'en_promo', 'prix_promo', 'date_debut_promo', 'date_fin_promo',
    ];

    protected $casts = [
        'prix_vente'       => 'decimal:2',
        'prix_achat'       => 'decimal:2',
        'prix_promo'       => 'decimal:2',
        'is_actif'         => 'boolean',
        'en_promo'         => 'boolean',
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

    public function categorie(): BelongsTo { return $this->belongsTo(Categorie::class); }
    public function variantes(): HasMany { return $this->hasMany(Variante::class); }
    public function images(): HasMany { return $this->hasMany(ProduitImage::class); }
}
