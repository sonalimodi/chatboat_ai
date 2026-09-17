<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ChatSession extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uuid',
        'owner_key',
        'title',
    ];

    /**
     * Boot the model and automatically assign a UUID when creating.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (ChatSession $chatSession) {
            if (empty($chatSession->uuid)) {
                $chatSession->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Use the UUID column for implicit route model binding.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * All messages belonging to this chat session, oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at');
    }
}
