<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A source document (e.g. the GPO Annual Report PDF) that has been
 * extracted, chunked, and embedded into the knowledge base.
 */
class RagDocument extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'source_path',
        'checksum',
        'strategy',
        'page_count',
        'chunk_count',
        'embedding_model',
        'embedding_dimensions',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function chunks()
    {
        return $this->hasMany(RagChunk::class)->orderBy('chunk_index');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sections()
    {
        return $this->hasMany(RagSection::class)->orderBy('ordinal');
    }
}
