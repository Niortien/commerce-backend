<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une mesure de vitesse (Web Vitals) envoyée par le navigateur d'un visiteur. */
class WebVital extends Model
{
    protected $table = 'web_vitals';
    public $timestamps = false;

    protected $fillable = ['metrique', 'valeur', 'note', 'page', 'appareil', 'connexion', 'created_at'];

    protected $casts = [
        'valeur'     => 'float',
        'created_at' => 'datetime',
    ];
}
