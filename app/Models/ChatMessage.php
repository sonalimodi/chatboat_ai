<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'chat_session_id',
        'role',
        'message',
    ];

    /**
     * Always load the attachment alongside every message - it's a single
     * cheap indexed lookup and both the UI (attachment chip) and
     * LlmService (context injection) need it almost everywhere a message
     * is fetched, so eager-loading it here avoids N+1 queries at every
     * call site instead of remembering to add ->with('attachment') to each.
     *
     * @var array
     */
    protected $with = ['attachment'];

    /**
     * The chat session this message belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function chatSession()
    {
        return $this->belongsTo(ChatSession::class);
    }

    /**
     * The single file (if any) uploaded alongside this message.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function attachment()
    {
        return $this->hasOne(ChatAttachment::class);
    }
}
