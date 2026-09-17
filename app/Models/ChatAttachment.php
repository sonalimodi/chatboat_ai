<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatAttachment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'chat_message_id',
        'original_filename',
        'mime_type',
        'disk_path',
        'size_bytes',
        'extracted_text',
    ];

    /**
     * The message this attachment was uploaded with.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function chatMessage()
    {
        return $this->belongsTo(ChatMessage::class);
    }
}
