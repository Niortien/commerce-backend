<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LookbookPhoto extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['url', 'nom', 'telephone', 'message', 'statut', 'publiee'];

    protected $casts = ['publiee' => 'boolean'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $m) {
            $m->id = (string) Str::uuid();
            $m->statut = $m->statut ?? 'nouveau';
        });
    }
}
