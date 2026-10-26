<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A node in an ingested document's heading tree: chapter, section,
 * subsection, box, figure, table or annex.
 */
class RagSection extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'rag_document_id',
        'parent_id',
        'ordinal',
        'level',
        'kind',
        'label',
        'title',
        'path',
        'page_start',
        'page_end',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function document()
    {
        return $this->belongsTo(RagDocument::class, 'rag_document_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function chunks()
    {
        return $this->hasMany(RagChunk::class)->orderBy('chunk_index');
    }
}
