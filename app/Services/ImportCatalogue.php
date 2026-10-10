<?php

namespace App\Services;

use App\Models\Categorie;
use App\Models\Entree;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Models\Variante;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import d'un catalogue en une fois (fichier CSV préparé dans Excel).
 *
 * Une ligne = un article (ou une taille/couleur d'un article). Les lignes qui portent le même nom
 * deviennent les variantes d'un même produit. Un article déjà connu n'est pas recréé : sa quantité
 * s'ajoute au stock. Le stock de départ passe par une entrée « Import du catalogue », comme un
 * arrivage normal, pour garder l'historique des mouvements.
 *
 * Les lignes en erreur sont écartées et signalées ; les autres sont importées.
 * En simulation, tout est vérifié puis annulé : le rapport dit ce qui se passerait.
 */
class ImportCatalogue
{
    public const MAX_LIGNES = 1000;
    private const TAILLE_UNIQUE = 'Unique';
    private const COULEUR_UNIQUE = '-';

    public function __construct(private StockMovementService $movements) {}

    /**
     * @param array<int, array<string, mixed>> $lignes
     * @return array{produitsCrees: int, variantesCreees: int, stocksAjoutes: int, categoriesCreees: int, entreeReference: string|null, erreurs: array<int, array{ligne: int, message: string}>, simulation: bool}
     */
    public function importer(string $boutiqueId, string $userId, array $lignes, bool $simulation, ?string $fournisseur = null): array
    {
        $rapport = [
            'produitsCrees' => 0, 'variantesCreees' => 0, 'stocksAjoutes' => 0, 'categoriesCreees' => 0,
            'entreeReference' => null, 'erreurs' => [], 'simulation' => $simulation,
        ];

        DB::beginTransaction();
        try {
            $categories = Categorie::where('boutique_id', $boutiqueId)->get()
                ->keyBy(fn(Categorie $c) => $this->cle($c->nom));
            $produits = Produit::with('variantes')->where('boutique_id', $boutiqueId)->get()
                ->keyBy(fn(Produit $p) => $this->cle($p->nom));
            $codesPris = Variante::where('boutique_id', $boutiqueId)->whereNotNull('code_barre')
                ->pluck('id', 'code_barre')->all();

            $aEntrer = [];
            foreach ($lignes as $i => $l) {
                $numero = $i + 1;
                $erreur = $this->verifier($l);
                if ($erreur) {
                    $rapport['erreurs'][] = ['ligne' => $numero, 'message' => $erreur];
                    continue;
                }

                $nom = trim((string) $l['nom']);
                $taille = trim((string) ($l['taille'] ?? '')) ?: self::TAILLE_UNIQUE;
                $couleur = trim((string) ($l['couleur'] ?? '')) ?: self::COULEUR_UNIQUE;
                $code = CodesBarres::normaliser(isset($l['codeBarre']) ? (string) $l['codeBarre'] : null);
                $quantite = (float) ($l['quantite'] ?? 0);

                $produit = $produits->get($this->cle($nom));
                $variante = $produit?->variantes->first(fn(Variante $v) => $this->cle($v->taille) === $this->cle($taille) && $this->cle($v->couleur) === $this->cle($couleur));

                if ($code !== null && isset($codesPris[$code]) && $codesPris[$code] !== $variante?->id) {
                    $rapport['erreurs'][] = ['ligne' => $numero, 'message' => "Le code-barres {$code} est déjà utilisé par un autre article"];
                    continue;
                }

                $unite = strtoupper(trim((string) ($l['unite'] ?? ''))) ?: 'PIECE';
                if ($quantite > 0 && in_array($unite, Produit::UNITES_ENTIERES, true) && floor($quantite) != $quantite) {
                    $rapport['erreurs'][] = ['ligne' => $numero, 'message' => "« {$nom} » se compte à l'unité : la quantité doit être un nombre entier"];
                    continue;
                }

                if (!$produit) {
                    $nomCategorie = trim((string) $l['categorie']);
                    $categorie = $categories->get($this->cle($nomCategorie));
                    if (!$categorie) {
                        $categorie = Categorie::create([
                            'boutique_id' => $boutiqueId,
                            'nom'         => $nomCategorie,
                            'slug'        => $this->slugLibre($boutiqueId, $nomCategorie),
                            'description' => 'Importées',
                        ]);
                        $categories->put($this->cle($nomCategorie), $categorie);
                        $rapport['categoriesCreees']++;
                    }

                    $produit = Produit::create([
                        'boutique_id'  => $boutiqueId,
                        'nom'          => $nom,
                        'sku'          => Str::slug($nom) . '-' . strtolower(Str::random(5)),
                        'categorie_id' => $categorie->id,
                        'prix_vente'   => (float) $l['prixVente'],
                        'prix_achat'   => (float) ($l['prixAchat'] ?? 0),
                        'unite'        => $unite,
                        'nature'       => 'ARTICLE',
                    ]);
                    $produit->setRelation('variantes', collect());
                    $produits->put($this->cle($nom), $produit);
                    $rapport['produitsCrees']++;
                }

                if (!$variante) {
                    $variante = Variante::create([
                        'produit_id'     => $produit->id,
                        'boutique_id'    => $boutiqueId,
                        'taille'         => $taille,
                        'couleur'        => $couleur,
                        'code_barre'     => $code,
                        'quantite_stock' => 0,
                        'seuil_alerte'   => isset($l['seuilAlerte']) && is_numeric($l['seuilAlerte']) ? (float) $l['seuilAlerte'] : 5,
                    ]);
                    $produit->variantes->push($variante);
                    $rapport['variantesCreees']++;
                } elseif ($code !== null && $variante->code_barre === null) {
                    $variante->update(['code_barre' => $code]);
                }
                if ($code !== null) $codesPris[$code] = $variante->id;

                if ($quantite > 0) {
                    $aEntrer[] = ['variante' => $variante, 'quantite' => $quantite, 'prix' => (float) ($l['prixAchat'] ?? $produit->prix_achat ?? 0)];
                    $rapport['stocksAjoutes']++;
                }
            }

            if ($aEntrer) {
                $rapport['entreeReference'] = $this->entreeDeStock($boutiqueId, $userId, $aEntrer, $fournisseur);
            }

            if ($simulation) {
                DB::rollBack();
            } else {
                DB::commit();
                Cache::forget("categories.{$boutiqueId}");
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $rapport;
    }

    /** @param array<string, mixed> $l */
    private function verifier(array $l): ?string
    {
        $nom = trim((string) ($l['nom'] ?? ''));
        if ($nom === '') return 'Le nom de l\'article manque';
        if (mb_strlen($nom) > 191) return 'Le nom est trop long';
        if (trim((string) ($l['categorie'] ?? '')) === '') return "« {$nom} » : la catégorie manque";
        if (!isset($l['prixVente']) || !is_numeric($l['prixVente']) || (float) $l['prixVente'] < 0) {
            return "« {$nom} » : le prix de vente doit être un nombre";
        }
        if (isset($l['prixAchat']) && $l['prixAchat'] !== '' && (!is_numeric($l['prixAchat']) || (float) $l['prixAchat'] < 0)) {
            return "« {$nom} » : le prix d'achat doit être un nombre";
        }
        if (isset($l['quantite']) && $l['quantite'] !== '' && (!is_numeric($l['quantite']) || (float) $l['quantite'] < 0)) {
            return "« {$nom} » : la quantité doit être un nombre positif";
        }
        $unite = strtoupper(trim((string) ($l['unite'] ?? '')));
        if ($unite !== '' && !in_array($unite, Produit::UNITES, true)) {
            return "« {$nom} » : unité « {$unite} » inconnue (" . implode(', ', Produit::UNITES) . ')';
        }
        if (isset($l['codeBarre']) && mb_strlen(trim((string) $l['codeBarre'])) > 64) {
            return "« {$nom} » : le code-barres est trop long";
        }
        return null;
    }

    /** @param array<int, array{variante: Variante, quantite: float, prix: float}> $aEntrer */
    private function entreeDeStock(string $boutiqueId, string $userId, array $aEntrer, ?string $fournisseur): string
    {
        $nomFournisseur = trim((string) $fournisseur) ?: 'Import du catalogue';
        $f = Fournisseur::firstOrCreate(['boutique_id' => $boutiqueId, 'nom' => $nomFournisseur], ['nom' => $nomFournisseur]);
        $reference = 'ENT-' . strtoupper(Str::random(8));
        $entree = Entree::create([
            'reference'      => $reference,
            'fournisseur'    => $nomFournisseur,
            'fournisseur_id' => $f->id,
            'total_cout'     => '0.00',
            'notes'          => 'Stock de départ importé depuis un fichier',
            'user_id'        => $userId,
            'boutique_id'    => $boutiqueId,
        ]);

        $total = '0.00';
        foreach ($aEntrer as $e) {
            $entree->lignes()->create([
                'variante_id'   => $e['variante']->id,
                'quantite'      => $e['quantite'],
                'prix_unitaire' => $e['prix'],
            ]);
            $this->movements->create($e['variante']->id, 'ENTREE', $e['quantite'], $userId, 'Import du catalogue', $reference);
            $total = bcadd($total, bcmul((string) $e['prix'], (string) $e['quantite'], 2), 2);
        }
        $entree->update(['total_cout' => $total]);

        return $reference;
    }

    private function cle(?string $texte): string
    {
        return mb_strtolower(trim((string) $texte));
    }

    private function slugLibre(string $boutiqueId, string $nom): string
    {
        $base = Str::slug($nom) ?: 'categorie';
        $slug = $base;
        $n = 2;
        while (Categorie::where('boutique_id', $boutiqueId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }
        return $slug;
    }
}
