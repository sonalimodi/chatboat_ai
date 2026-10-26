<?php

namespace App\Models;

use App\Services\Rag\VectorMath;
use Illuminate\Database\Eloquent\Model;

/**
 * One retrievable passage of a RagDocument, with the page range it came
 * from, its embedding vector, and - for structured documents - the
 * section it belongs to.
 */
class RagChunk extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'rag_document_id',
        'rag_section_id',
        'chunk_index',
        'kind',
        'section_path',
        'page_start',
        'page_end',
        'page_label',
        'content',
        'embedding',
    ];

    /**
     * Never serialise the (large, binary-ish) vector into JSON responses.
     *
     * @var array
     */
    protected $hidden = ['embedding'];

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
    public function section()
    {
        return $this->belongsTo(RagSection::class, 'rag_section_id');
    }

    /**
     * @return array<int, float>
     */
    public function vector(): array
    {
        return VectorMath::unpack($this->embedding);
    }

    /**
     * The text that is embedded and keyword-indexed: the chunk prefixed
     * with its section breadcrumb, so a passage that never names its
     * subject ("Growth in the region is projected to...") is still found
     * by "South Asia outlook".
     *
     * @return string
     */
    public function searchableText(): string
    {
        return static::searchableTextFor($this->content, $this->section_path);
    }

    /**
     * @param  string  $content
     * @param  string|null  $sectionPath
     * @return string
     */
    public static function searchableTextFor(string $content, ?string $sectionPath): string
    {
        return $sectionPath ? "Section: {$sectionPath}\n\n{$content}" : $content;
    }

    /**
     * "p. 14" / "pp. 14-15" - printed page numbers when the document has
     * them, with the PDF page alongside so either can be looked up.
     *
     * @return string
     */
    public function pageLabel(): string
    {
        $pdf = $this->page_start === $this->page_end
            ? (string) $this->page_start
            : $this->page_start . '-' . $this->page_end;

        if ($this->page_label) {
            $prefix = strpos($this->page_label, '-') === false ? 'p. ' : 'pp. ';

            return $prefix . $this->page_label . ' (PDF ' . $pdf . ')';
        }

        return ($this->page_start === $this->page_end ? 'p. ' : 'pp. ') . $pdf;
    }
}
