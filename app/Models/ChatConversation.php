<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class ChatConversation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['admin_id'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function admin(): BelongsTo { return $this->belongsTo(User::class, 'admin_id'); }
    public function messages(): HasMany { return $this->hasMany(ChatMessage::class, 'conversation_id'); }
    public function lastMessage(): HasOne { return $this->hasOne(ChatMessage::class, 'conversation_id')->latestOfMany(); }
}
