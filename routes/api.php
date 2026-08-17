<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoutiqueController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\CaisseController;
use App\Http\Controllers\EntreeController;
use App\Http\Controllers\FournisseurController;
use App\Http\Controllers\LookbookPhotoController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\SortieController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VarianteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Auth (public) ──────────────────────────────────────────────────────
    Route::post('auth/login',           [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('auth/refresh',         [AuthController::class, 'refresh']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
    Route::post('auth/reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');

    // ── Public catalogue ───────────────────────────────────────────────────
    Route::get('produits/categories', [ProduitController::class, 'categories']);
    Route::get('produits',            [ProduitController::class, 'index']);
    Route::get('produits/{id}',      [ProduitController::class, 'show']);

    // ── Lookbook (upload photo client, public) ─────────────────────────────
    Route::post('lookbook-photos', [LookbookPhotoController::class, 'store'])->middleware('throttle:10,1');
    Route::get('lookbook-photos/publiees', [LookbookPhotoController::class, 'publicIndex']);

    // ── Protected routes ───────────────────────────────────────────────────
    Route::middleware('auth:api')->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Produits (write)
        Route::post('produits',                        [ProduitController::class, 'store']);
        Route::patch('produits/{id}',                  [ProduitController::class, 'update']);
        Route::patch('produits/{id}/boutique',         [ProduitController::class, 'reassignBoutique']);
        Route::delete('produits/{id}',                 [ProduitController::class, 'destroy']);
        Route::post('produits/{id}/variantes',           [ProduitController::class, 'addVariante']);
        Route::post('produits/{id}/images',            [ProduitController::class, 'addImage']);
        Route::delete('produits/{id}/images/{imageId}',[ProduitController::class, 'removeImage']);
        Route::get('produits/{id}/mouvements',         [ProduitController::class, 'mouvements']);

        // Variantes
        Route::patch('variantes/{id}',       [VarianteController::class, 'update']);
        Route::delete('variantes/{id}',      [VarianteController::class, 'destroy']);

        // Stock
        Route::get('stock',           [StockController::class, 'index']);
        Route::get('stock/alertes',   [StockController::class, 'alertes']);
        Route::get('stock/mouvements',[StockController::class, 'mouvements']);

        // Fournisseurs
        Route::get('fournisseurs',      [FournisseurController::class, 'index']);
        Route::get('fournisseurs/{id}', [FournisseurController::class, 'show']);
        Route::post('fournisseurs',     [FournisseurController::class, 'store']);

        // Entrées
        Route::get('entrees',              [EntreeController::class, 'index']);
        Route::get('entrees/{id}',         [EntreeController::class, 'show']);
        Route::post('entrees',             [EntreeController::class, 'store']);
        Route::patch('entrees/{id}',       [EntreeController::class, 'update']);

        // Sorties
        Route::get('sorties',              [SortieController::class, 'index']);
        Route::get('sorties/{id}',         [SortieController::class, 'show']);
        Route::post('sorties',             [SortieController::class, 'store']);
        Route::patch('sorties/{id}',       [SortieController::class, 'update']);

        // Caisse
        Route::get('caisse/sessions',                          [CaisseController::class, 'listSessions']);
        Route::get('caisse/sessions/active',                   [CaisseController::class, 'activeSession']);
        Route::post('caisse/sessions/ouvrir',                  [CaisseController::class, 'openSession']);
        Route::post('caisse/sessions/{id}/fermer',             [CaisseController::class, 'closeSession']);
        Route::get('caisse/sessions/{id}/transactions',        [CaisseController::class, 'listTransactions']);
        Route::post('caisse/transactions',                     [CaisseController::class, 'createTransaction']);
        Route::get('caisse/resume-jour',                       [CaisseController::class, 'resumeJour']);

        // Rapports
        Route::get('rapports/resume-dashboard', [RapportController::class, 'resumeDashboard']);
        Route::get('rapports/ventes',           [RapportController::class, 'ventes']);
        Route::get('rapports/stock-valeur',     [RapportController::class, 'stockValeur']);
        Route::get('rapports/top-produits',     [RapportController::class, 'topProduits']);
        Route::get('rapports/flux-tresorerie',  [RapportController::class, 'fluxTresorerie']);
        Route::get('rapports/depenses',         [RapportController::class, 'depenses']);
        Route::get('rapports/recette-hebdomadaire', [RapportController::class, 'recetteHebdomadaire']);
        Route::get('rapports/export/excel',     [RapportController::class, 'exportExcel']);
        Route::get('rapports/export/pdf',       [RapportController::class, 'exportPdf']);

        // ── ADMIN + GERANT (lecture élargie, écriture sensible réservée à ADMIN) ─
        Route::middleware('role:ADMIN,GERANT')->group(function () {
            Route::get('categories',        [CategorieController::class, 'index']);
            Route::get('boutiques',         [BoutiqueController::class, 'index']);
            Route::get('boutiques/{id}',    [BoutiqueController::class, 'show']);

            Route::patch('fournisseurs/{id}',  [FournisseurController::class, 'update']);
            Route::delete('fournisseurs/{id}', [FournisseurController::class, 'destroy']);

            Route::post('stock/transferts', [StockController::class, 'transferer']);

            // Photos clients (lookbook)
            Route::get('lookbook-photos',            [LookbookPhotoController::class, 'index']);
            Route::patch('lookbook-photos/{id}',     [LookbookPhotoController::class, 'updateStatut']);
            Route::patch('lookbook-photos/{id}/publier', [LookbookPhotoController::class, 'updatePubliee']);
            Route::delete('lookbook-photos/{id}',    [LookbookPhotoController::class, 'destroy']);
        });

        // ── Admin-only ──────────────────────────────────────────────────────
        Route::middleware('role:ADMIN')->group(function () {

            // Catégories (écriture admin)
            Route::post('categories',        [CategorieController::class, 'store']);
            Route::patch('categories/{id}',  [CategorieController::class, 'update']);
            Route::delete('categories/{id}', [CategorieController::class, 'destroy']);

            // Users
            Route::get('users',         [UserController::class, 'index']);
            Route::post('users',        [UserController::class, 'store']);
            Route::get('users/{id}',    [UserController::class, 'show']);
            Route::patch('users/{id}',  [UserController::class, 'update']);
            Route::delete('users/{id}', [UserController::class, 'destroy']);

            // Boutiques (écriture admin)
            Route::post('boutiques',        [BoutiqueController::class, 'store']);
            Route::patch('boutiques/{id}',  [BoutiqueController::class, 'update']);
            Route::delete('boutiques/{id}', [BoutiqueController::class, 'destroy']);

            // Ajustement manuel de stock (+/-) — réservé ADMIN
            Route::patch('variantes/{id}/stock', [VarianteController::class, 'adjustStock']);

            // Annulation / suppression de mouvements comptables — réservé ADMIN
            Route::delete('entrees/{id}',        [EntreeController::class, 'destroy']);
            Route::patch('entrees/{id}/annuler', [EntreeController::class, 'annuler']);
            Route::delete('sorties/{id}',        [SortieController::class, 'destroy']);
            Route::patch('sorties/{id}/annuler', [SortieController::class, 'annuler']);

            // Journal d'audit
            Route::get('audit-logs', [AuditLogController::class, 'index']);
        });
    });
});
