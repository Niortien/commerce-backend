<?php

namespace App\Http\Controllers;

/**
 * @OA\Info(
 *     title="Habit Backend API",
 *     version="1.0.0",
 *     description="API de gestion de boutiques de vêtements — stocks, ventes, caisse, rapports.",
 *     @OA\Contact(email="admin@shop.com")
 * )
 *
 * @OA\Server(url="/api/v1", description="API v1")
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 *
 * @OA\Schema(
 *     schema="ApiResponse",
 *     @OA\Property(property="data", type="object"),
 *     @OA\Property(property="meta", type="object", nullable=true),
 *     @OA\Property(property="timestamp", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="PaginatedMeta",
 *     @OA\Property(property="total", type="integer"),
 *     @OA\Property(property="page", type="integer"),
 *     @OA\Property(property="limit", type="integer"),
 *     @OA\Property(property="pageCount", type="integer")
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     @OA\Property(property="error", type="object",
 *         @OA\Property(property="code", type="string"),
 *         @OA\Property(property="message", type="string"),
 *         @OA\Property(property="details", type="object", nullable=true)
 *     ),
 *     @OA\Property(property="path", type="string"),
 *     @OA\Property(property="timestamp", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="User",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="email", type="string", format="email"),
 *     @OA\Property(property="role", type="string", enum={"ADMIN","VENDEUR"}),
 *     @OA\Property(property="boutique_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="Boutique",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="nom", type="string"),
 *     @OA\Property(property="adresse", type="string", nullable=true),
 *     @OA\Property(property="telephone", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="Categorie",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="nom", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="Produit",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="nom", type="string"),
 *     @OA\Property(property="sku", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="prix_vente", type="number"),
 *     @OA\Property(property="prix_achat", type="number"),
 *     @OA\Property(property="image_url", type="string", nullable=true),
 *     @OA\Property(property="is_actif", type="boolean"),
 *     @OA\Property(property="categorie_id", type="string", format="uuid"),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="Variante",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="produit_id", type="string", format="uuid"),
 *     @OA\Property(property="boutique_id", type="string", format="uuid"),
 *     @OA\Property(property="taille", type="string"),
 *     @OA\Property(property="couleur", type="string"),
 *     @OA\Property(property="quantite_stock", type="integer"),
 *     @OA\Property(property="seuil_alerte", type="integer")
 * )
 *
 * @OA\Schema(
 *     schema="MouvementStock",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="variante_id", type="string", format="uuid"),
 *     @OA\Property(property="type", type="string", enum={"ENTREE","SORTIE","AJUSTEMENT","RETOUR"}),
 *     @OA\Property(property="quantite", type="integer"),
 *     @OA\Property(property="motif", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="CaisseSession",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="boutique_id", type="string", format="uuid"),
 *     @OA\Property(property="user_id", type="string", format="uuid"),
 *     @OA\Property(property="statut", type="string", enum={"OUVERTE","FERMEE"}),
 *     @OA\Property(property="montant_ouverture", type="number"),
 *     @OA\Property(property="montant_fermeture", type="number", nullable=true),
 *     @OA\Property(property="date_ouverture", type="string", format="date-time"),
 *     @OA\Property(property="date_fermeture", type="string", format="date-time", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="Transaction",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="session_id", type="string", format="uuid"),
 *     @OA\Property(property="montant", type="number"),
 *     @OA\Property(property="mode_paiement", type="string", enum={"CASH","WAVE","ORANGE_MONEY","CARTE","MTN_MONEY"}),
 *     @OA\Property(property="reference", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 */
class SwaggerInfo {}
