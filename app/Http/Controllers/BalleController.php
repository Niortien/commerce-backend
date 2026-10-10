<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Traits\ApiResponse;
use App\Models\Balle;
use App\Models\Categorie;
use App\Models\Entree;
use App\Models\Fournisseur;
use App\Models\LigneEntree;
use App\Models\Produit;
use App\Models\Variante;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Friperie : balles achetées en bloc puis déballées pièce par pièce.
 *
 * - Créer une balle enregistre la dépense (une entrée sans ligne, pour la trésorerie).
 * - Chaque pièce déballée devient un produit unique (stock 1) rattaché à la balle.
 * - Le coût de la balle (achat + frais) est réparti à parts égales sur ses pièces, recalculé à chaque ajout.
 * - Le bilan compare ce que la balle a rapporté en ventes à ce qu'elle a coûté.
 */
class BalleController extends Controller
{
    use ApiResponse;

    /** Pièces ajoutées en une fois : un déballage se saisit par paquets. */
    private const PIECES_PAR_ENVOI = 50;

    public function __construct(private StockMovementService $movements) {}

    private function findOwned(Request $request, string $id): Balle
    {
        $balle = Balle::where('boutique_id', $this->tenantBoutiqueId($request))->find($id);
        if (!$balle) throw new NotFoundException('Balle introuvable', 'BALLE_NOT_FOUND');
        return $balle;
    }

    public function index(Request $request): JsonResponse
    {
        $q = Balle::where('boutique_id', $this->tenantBoutiqueId($request))->orderBy('numero', 'desc');

        if ($request->filled('statut')) $q->where('statut', $request->statut);
        if ($request->filled('search')) {
            $search = $request->search;
            $q->where(fn($w) => $w->where('libelle', 'like', "%{$search}%")->orWhere('fournisseur', 'like', "%{$search}%"));
        }

        $page  = max(1, (int) $request->get('page', 1));
        $limit = min(100, max(1, (int) $request->get('limit', 20)));
        $total = $q->count();
        $balles = $q->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->paginated($this->avecBilan($balles), $total, $page, $limit);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $balle = $this->findOwned($request, $id);
        $data = $this->avecBilan(collect([$balle]))->first();
        $data['pieces'] = Produit::with(['categorie', 'variantes'])
            ->where('balle_id', $balle->id)
            ->orderBy('numero_piece', 'desc')
            ->get();
        $data['par_choix'] = $this->bilanParChoix($balle);

        return $this->success($data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'libelle'     => 'required|string|max:150',
            'fournisseur' => 'sometimes|nullable|string|max:150',
            'coutAchat'   => 'required|numeric|min:0',
            'frais'       => 'sometimes|nullable|numeric|min:0',
            'dateAchat'   => 'sometimes|nullable|date',
            'notes'       => 'sometimes|nullable|string',
            'prixChoix1'  => 'sometimes|nullable|numeric|min:0',
            'prixChoix2'  => 'sometimes|nullable|numeric|min:0',
            'prixChoix3'  => 'sometimes|nullable|numeric|min:0',
        ]);

        $boutiqueId = $this->tenantBoutiqueId($request);
        $userId = $request->user()->id;
        $fournisseurNom = trim((string) ($data['fournisseur'] ?? '')) ?: null;

        $balle = DB::transaction(function () use ($data, $boutiqueId, $userId, $fournisseurNom) {
            // Numéro suivant de la boutique, sous verrou pour éviter deux « Balle n°8 ».
            $numero = (int) Balle::where('boutique_id', $boutiqueId)->lockForUpdate()->max('numero') + 1;
            $libelle = trim($data['libelle']);
            $coutAchat = number_format((float) $data['coutAchat'], 2, '.', '');
            $frais = number_format((float) ($data['frais'] ?? 0), 2, '.', '');

            $fournisseur = $fournisseurNom
                ? Fournisseur::firstOrCreate(['boutique_id' => $boutiqueId, 'nom' => $fournisseurNom], ['nom' => $fournisseurNom])
                : null;

            // La dépense apparaît dans les entrées (trésorerie) ; ses lignes viendront avec les pièces.
            $entree = Entree::create([
                'reference'      => 'ENT-' . strtoupper(Str::random(8)),
                'fournisseur'    => $fournisseurNom ?? "Balle n°{$numero}",
                'fournisseur_id' => $fournisseur?->id,
                'total_cout'     => bcadd($coutAchat, $frais, 2),
                'notes'          => "Balle n°{$numero} — {$libelle}",
                'user_id'        => $userId,
                'boutique_id'    => $boutiqueId,
            ]);

            return Balle::create([
                'boutique_id'    => $boutiqueId,
                'numero'         => $numero,
                'libelle'        => $libelle,
                'fournisseur'    => $fournisseurNom,
                'fournisseur_id' => $fournisseur?->id,
                'cout_achat'     => $coutAchat,
                'frais'          => $frais,
                'date_achat'     => $data['dateAchat'] ?? now()->toDateString(),
                'statut'         => 'EN_COURS',
                'prix_choix_1'   => $data['prixChoix1'] ?? null,
                'prix_choix_2'   => $data['prixChoix2'] ?? null,
                'prix_choix_3'   => $data['prixChoix3'] ?? null,
                'entree_id'      => $entree->id,
                'notes'          => $data['notes'] ?? null,
                'user_id'        => $userId,
            ]);
        });

        return $this->success($this->avecBilan(collect([$balle]))->first(), 201);
    }

    /** Corriger le libellé, le fournisseur ou le coût : la répartition sur les pièces suit. */
    public function update(Request $request, string $id): JsonResponse
    {
        $balle = $this->findOwned($request, $id);
        $data = $request->validate([
            'libelle'     => 'sometimes|string|max:150',
            'fournisseur' => 'sometimes|nullable|string|max:150',
            'coutAchat'   => 'sometimes|numeric|min:0',
            'frais'       => 'sometimes|nullable|numeric|min:0',
            'dateAchat'   => 'sometimes|date',
            'notes'       => 'sometimes|nullable|string',
            'prixChoix1'  => 'sometimes|nullable|numeric|min:0',
            'prixChoix2'  => 'sometimes|nullable|numeric|min:0',
            'prixChoix3'  => 'sometimes|nullable|numeric|min:0',
        ]);

        $changes = [];
        foreach (Produit::CHOIX as $c) {
            if (array_key_exists("prixChoix{$c}", $data)) $changes["prix_choix_{$c}"] = $data["prixChoix{$c}"];
        }
        if (array_key_exists('libelle', $data))     $changes['libelle'] = trim($data['libelle']);
        if (array_key_exists('fournisseur', $data)) $changes['fournisseur'] = trim((string) $data['fournisseur']) ?: null;
        if (array_key_exists('coutAchat', $data))   $changes['cout_achat'] = $data['coutAchat'];
        if (array_key_exists('frais', $data))       $changes['frais'] = $data['frais'] ?? 0;
        if (array_key_exists('dateAchat', $data))   $changes['date_achat'] = $data['dateAchat'];
        if (array_key_exists('notes', $data))       $changes['notes'] = $data['notes'];

        DB::transaction(function () use ($balle, $changes) {
            $balle->update($changes);
            $balle->refresh();
            $balle->entree?->update([
                'total_cout' => $balle->coutTotal(),
                'notes'      => "Balle n°{$balle->numero} — {$balle->libelle}",
            ]);
            $this->repartirCout($balle);
        });

        return $this->success($this->avecBilan(collect([$balle->fresh()]))->first());
    }

    /** Déballage terminé (plus d'ajout) ou rouvert. */
    public function changerStatut(Request $request, string $id): JsonResponse
    {
        $balle = $this->findOwned($request, $id);
        $data = $request->validate(['statut' => 'required|in:' . implode(',', Balle::STATUTS)]);
        $balle->update(['statut' => $data['statut']]);

        return $this->success($this->avecBilan(collect([$balle->fresh()]))->first());
    }

    /**
     * Ajoute les pièces trouvées au déballage. Chacune devient un article unique en rayon (stock 1),
     * numérotée dans la balle (B7-014) pour l'étiquette.
     */
    public function ajouterPieces(Request $request, string $id): JsonResponse
    {
        $balle = $this->findOwned($request, $id);
        $data = $request->validate([
            'pieces'               => 'required|array|min:1|max:' . self::PIECES_PAR_ENVOI,
            'pieces.*.nom'         => 'required|string|max:150',
            'pieces.*.categorieId' => 'required|uuid',
            'pieces.*.prixVente'   => 'required|numeric|min:0',
            'pieces.*.description' => 'sometimes|nullable|string',
            'pieces.*.choix'       => 'sometimes|nullable|integer|in:' . implode(',', Produit::CHOIX),
        ]);

        if ($balle->statut === 'TERMINEE') {
            throw new ConflictException('Le déballage de cette balle est terminé : rouvrez-le pour ajouter des pièces', 'BALLE_TERMINEE');
        }

        $boutiqueId = $balle->boutique_id;
        $categories = Categorie::where('boutique_id', $boutiqueId)
            ->whereIn('id', array_unique(array_column($data['pieces'], 'categorieId')))
            ->pluck('id')
            ->all();

        $userId = $request->user()->id;
        $creees = DB::transaction(function () use ($balle, $data, $boutiqueId, $categories, $userId) {
            $balle = Balle::whereKey($balle->id)->lockForUpdate()->first();
            $entree = $balle->entree;
            $numero = (int) Produit::where('balle_id', $balle->id)->max('numero_piece');
            $ids = [];

            foreach ($data['pieces'] as $piece) {
                if (!in_array($piece['categorieId'], $categories, true)) {
                    throw new NotFoundException('Rayon introuvable', 'CATEGORIE_NOT_FOUND');
                }
                $numero++;

                $produit = Produit::create([
                    'boutique_id'  => $boutiqueId,
                    'nom'          => trim($piece['nom']),
                    'sku'          => $this->codePiece($boutiqueId, $balle->numero, $numero),
                    'description'  => $piece['description'] ?? null,
                    'categorie_id' => $piece['categorieId'],
                    'prix_vente'   => $piece['prixVente'],
                    'prix_achat'   => 0,
                    'unite'        => 'PIECE',
                    'nature'       => 'ARTICLE',
                    'balle_id'     => $balle->id,
                    'numero_piece' => $numero,
                    'piece_unique' => true,
                    'choix'        => $piece['choix'] ?? null,
                ]);
                $variante = Variante::create([
                    'produit_id'     => $produit->id,
                    'boutique_id'    => $boutiqueId,
                    'taille'         => 'Unique',
                    'couleur'        => '-',
                    'quantite_stock' => 0,
                    'seuil_alerte'   => 0,
                ]);

                if ($entree) {
                    $entree->lignes()->create(['variante_id' => $variante->id, 'quantite' => 1, 'prix_unitaire' => 0]);
                }
                $this->movements->create($variante->id, 'ENTREE', 1, $userId, "Déballage balle n°{$balle->numero}", $entree?->reference);
                $ids[] = $produit->id;
            }

            $this->repartirCout($balle);
            return $ids;
        });

        return $this->success(
            Produit::with(['categorie', 'variantes'])->whereIn('id', $creees)->orderBy('numero_piece')->get(),
            201
        );
    }

    /** Retirer une pièce saisie par erreur. Une pièce déjà vendue reste : son historique compte. */
    public function retirerPiece(Request $request, string $id, string $produitId): JsonResponse
    {
        $balle = $this->findOwned($request, $id);
        $produit = Produit::with('variantes')->where('balle_id', $balle->id)->find($produitId);
        if (!$produit) throw new NotFoundException('Pièce introuvable dans cette balle', 'PIECE_NOT_FOUND');

        $vendue = $produit->variantes->every(fn($v) => (float) $v->quantite_stock <= 0);
        $aBouge = \App\Models\LigneSortie::whereIn('variante_id', $produit->variantes->pluck('id'))->exists();
        if ($vendue || $aBouge) {
            throw new ConflictException('Cette pièce est déjà passée en caisse : elle ne peut plus être retirée', 'PIECE_DEJA_VENDUE');
        }

        DB::transaction(function () use ($balle, $produit) {
            LigneEntree::whereIn('variante_id', $produit->variantes->pluck('id'))->delete();
            $produit->delete();
            $this->repartirCout($balle);
        });

        return $this->success(['id' => $produitId]);
    }

    /** Supprimer une balle créée par erreur : seulement tant qu'elle est vide. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $balle = $this->findOwned($request, $id);
        if (Produit::where('balle_id', $balle->id)->exists()) {
            throw new ConflictException('Cette balle contient des pièces : retirez-les avant de la supprimer', 'BALLE_NON_VIDE');
        }

        DB::transaction(function () use ($balle) {
            $entree = $balle->entree;
            $balle->delete();
            $entree?->delete();
        });

        return $this->success(['id' => $id]);
    }

    /**
     * Tri par qualité : pièces, pièces en rayon et ventes encaissées pour chaque choix (null = non trié).
     *
     * @return array<int, array<string, mixed>>
     */
    private function bilanParChoix(Balle $balle): array
    {
        $pieces = DB::table('produits as p')
            ->join('variantes as v', 'v.produit_id', '=', 'p.id')
            ->where('p.balle_id', $balle->id)
            ->groupBy('p.choix')
            ->selectRaw('p.choix, COUNT(DISTINCT p.id) as nb_pieces, COUNT(DISTINCT CASE WHEN v.quantite_stock > 0 THEN p.id END) as nb_en_rayon')
            ->get()
            ->keyBy(fn($r) => (string) $r->choix);

        $ventes = DB::table('ligne_sorties as ls')
            ->join('sorties as s', 's.id', '=', 'ls.sortie_id')
            ->join('variantes as v', 'v.id', '=', 'ls.variante_id')
            ->join('produits as p', 'p.id', '=', 'v.produit_id')
            ->where('p.balle_id', $balle->id)
            ->where('s.type', 'VENTE')
            ->where(fn($w) => $w->whereNull('s.notes')->orWhere('s.notes', 'not like', '[ANNULÉE]%'))
            ->groupBy('p.choix')
            ->selectRaw('p.choix, COALESCE(SUM(ls.quantite * ls.prix_unitaire
                * CASE WHEN s.total_avant_remise > 0 THEN s.total_montant / s.total_avant_remise ELSE 1 END), 0) as recette')
            ->get()
            ->keyBy(fn($r) => (string) $r->choix);

        return $pieces->map(fn($p, $cle) => [
            'choix'       => $p->choix === null ? null : (int) $p->choix,
            'nb_pieces'   => (int) $p->nb_pieces,
            'nb_en_rayon' => (int) $p->nb_en_rayon,
            'nb_vendues'  => (int) $p->nb_pieces - (int) $p->nb_en_rayon,
            'recette'     => number_format((float) ($ventes->get($cle)->recette ?? 0), 2, '.', ''),
        ])->sortBy(fn($r) => $r['choix'] ?? 99)->values()->all();
    }

    /** Coût de la balle ÷ nombre de pièces, sur chaque pièce et chaque ligne de l'entrée. */
    private function repartirCout(Balle $balle): void
    {
        $nb = Produit::where('balle_id', $balle->id)->count();
        if ($nb === 0) return;

        $part = bcdiv($balle->coutTotal(), (string) $nb, 2);
        Produit::where('balle_id', $balle->id)->update(['prix_achat' => $part]);

        if ($balle->entree_id) {
            $variantes = Variante::whereIn('produit_id', Produit::where('balle_id', $balle->id)->select('id'))->select('id');
            LigneEntree::where('entree_id', $balle->entree_id)->whereIn('variante_id', $variantes)->update(['prix_unitaire' => $part]);
        }
    }

    /** B7-014 ; suffixé si ce code est déjà pris par un autre article de la boutique. */
    private function codePiece(string $boutiqueId, int $numeroBalle, int $numeroPiece): string
    {
        $code = sprintf('B%d-%03d', $numeroBalle, $numeroPiece);
        $libre = $code;
        $i = 2;
        while (Produit::where('boutique_id', $boutiqueId)->where('sku', $libre)->exists()) {
            $libre = "{$code}-{$i}";
            $i++;
        }
        return $libre;
    }

    /**
     * Ajoute à chaque balle son bilan : pièces en rayon / vendues, ce qu'elle a rapporté (ventes non annulées,
     * remise répartie au prorata), sa marge et la part de son coût déjà remboursée.
     *
     * @param Collection<int, Balle> $balles
     * @return Collection<int, array<string, mixed>>
     */
    private function avecBilan(Collection $balles): Collection
    {
        $ids = $balles->pluck('id')->all();

        $pieces = $ids === [] ? collect() : DB::table('produits as p')
            ->join('variantes as v', 'v.produit_id', '=', 'p.id')
            ->whereIn('p.balle_id', $ids)
            ->groupBy('p.balle_id')
            ->selectRaw('p.balle_id, COUNT(DISTINCT p.id) as nb_pieces,
                COUNT(DISTINCT CASE WHEN v.quantite_stock > 0 THEN p.id END) as nb_en_rayon,
                COALESCE(SUM(CASE WHEN v.quantite_stock > 0 THEN p.prix_vente ELSE 0 END), 0) as valeur_en_rayon')
            ->get()
            ->keyBy('balle_id');

        $ventes = $ids === [] ? collect() : DB::table('ligne_sorties as ls')
            ->join('sorties as s', 's.id', '=', 'ls.sortie_id')
            ->join('variantes as v', 'v.id', '=', 'ls.variante_id')
            ->join('produits as p', 'p.id', '=', 'v.produit_id')
            ->whereIn('p.balle_id', $ids)
            ->where('s.type', 'VENTE')
            ->where(fn($w) => $w->whereNull('s.notes')->orWhere('s.notes', 'not like', '[ANNULÉE]%'))
            ->groupBy('p.balle_id')
            ->selectRaw('p.balle_id, COALESCE(SUM(ls.quantite * ls.prix_unitaire
                * CASE WHEN s.total_avant_remise > 0 THEN s.total_montant / s.total_avant_remise ELSE 1 END), 0) as recette')
            ->get()
            ->keyBy('balle_id');

        return $balles->map(function (Balle $balle) use ($pieces, $ventes) {
            $p = $pieces->get($balle->id);
            $nbPieces = (int) ($p->nb_pieces ?? 0);
            $nbEnRayon = (int) ($p->nb_en_rayon ?? 0);
            $cout = $balle->coutTotal();
            $recette = number_format((float) ($ventes->get($balle->id)->recette ?? 0), 2, '.', '');

            return array_merge($balle->toArray(), [
                'cout_total'        => $cout,
                'nb_pieces'         => $nbPieces,
                'nb_en_rayon'       => $nbEnRayon,
                'nb_vendues'        => $nbPieces - $nbEnRayon,
                'cout_par_piece'    => $nbPieces > 0 ? bcdiv($cout, (string) $nbPieces, 2) : null,
                'recette_ventes'    => $recette,
                'valeur_en_rayon'   => number_format((float) ($p->valeur_en_rayon ?? 0), 2, '.', ''),
                'marge'             => bcsub($recette, $cout, 2),
                // Part du coût déjà revenue en caisse (peut dépasser 100 : la balle est rentabilisée).
                'taux_rembourse'    => bccomp($cout, '0', 2) > 0 ? round((float) $recette / (float) $cout * 100, 1) : null,
            ]);
        });
    }
}
