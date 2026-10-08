<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['conversation_id', 'sender_id', 'body', 'read_at'];
    protected $casts = ['read_at' => 'datetime'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }

    public function conversation(): BelongsTo { return $this->belongsTo(ChatConversation::class, 'conversation_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }
}
