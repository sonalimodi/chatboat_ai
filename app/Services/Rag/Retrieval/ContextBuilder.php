<?php

namespace App\Services\Rag\Retrieval;

use App\Models\RagChunk;

/**
 * Turns ranked chunks into the passages actually shown to the LLM.
 *
 * Retrieval works best on small chunks; answering works best on whole
 * paragraphs. So, for structured documents, each hit is widened with its
 * neighbouring chunks from the same section ("small-to-big"), hits that
 * end up touching are merged into one passage (de-duplicating the overlap
 * text between consecutive chunks), and passages are taken in relevance
 * order until the character budget is spent. A hit is never cut: if its
 * widened passage doesn't fit, the bare chunk is tried instead.
 *
 * Flat-strategy documents have no sections, so their hits pass through
 * unchanged (one passage per chunk).
 */
class ContextBuilder
{
    /**
     * @var \App\Services\Rag\Retrieval\HybridRetriever
     */
    protected $retriever;

    public function __construct(HybridRetriever $retriever)
    {
        $this->retriever = $retriever;
    }

    /**
     * @param  array  $results  HybridRetriever::retrieve() output, best first
     * @param  int|null  $maxChars
     * @param  int|null  $window
     * @return array<int, array{chunks: array<int, \App\Models\RagChunk>, hit: \App\Models\RagChunk, score: float, content: string}>
     */
    public function build(array $results, ?int $maxChars = null, ?int $window = null): array
    {
        $maxChars = $maxChars ?? (int) config('rag.context.max_chars', 12000);
        $window = $window ?? (int) config('rag.context.neighbor_window', 1);

        $passages = [];   // each: ['key' => docId:sectionId, 'chunks' => [index => chunk], 'hit', 'score']
        $used = [];       // chunk id => passage index
        $total = 0;

        foreach ($results as $result) {
            $chunk = $result['chunk'];
            if (isset($used[$chunk->id])) {
                continue;
            }

            $widened = $this->retriever->neighbors($chunk, $window) ?: [$chunk->chunk_index => $chunk];
            $candidates = count($widened) > 1 ? [$widened, [$chunk->chunk_index => $chunk]] : [$widened];

            foreach ($candidates as $group) {
                $group = array_filter($group, function (RagChunk $c) use ($used) {
                    return ! isset($used[$c->id]);
                });

                // Join an existing passage of the same section if contiguous.
                $target = $this->adjacentPassage($passages, $group);
                $merged = $target === null ? $group : $passages[$target]['chunks'] + $group;
                ksort($merged);
                $newLength = mb_strlen($this->merge($merged));
                $delta = $newLength - ($target === null ? 0 : mb_strlen($passages[$target]['content']));

                if ($total + $delta > $maxChars && ! empty($passages)) {
                    continue;
                }

                if ($target === null) {
                    $passages[] = ['chunks' => $merged, 'hit' => $chunk, 'score' => $result['score'], 'content' => $this->merge($merged)];
                    $target = count($passages) - 1;
                } else {
                    $passages[$target]['chunks'] = $merged;
                    $passages[$target]['content'] = $this->merge($merged);
                }
                foreach ($group as $c) {
                    $used[$c->id] = $target;
                }
                $total += $delta;
                break;
            }
        }

        return $passages;
    }

    /**
     * Index of a passage in the same document section that the group
     * touches or overlaps (by chunk index), if any.
     *
     * @param  array  $passages
     * @param  array<int, \App\Models\RagChunk>  $group
     * @return int|null
     */
    protected function adjacentPassage(array $passages, array $group): ?int
    {
        if (empty($group)) {
            return null;
        }

        $first = reset($group);
        if ($first->rag_section_id === null) {
            return null;
        }

        $min = min(array_keys($group));
        $max = max(array_keys($group));

        foreach ($passages as $i => $passage) {
            $other = reset($passage['chunks']);
            if ($other->rag_document_id !== $first->rag_document_id || $other->rag_section_id !== $first->rag_section_id) {
                continue;
            }
            $pMin = min(array_keys($passage['chunks']));
            $pMax = max(array_keys($passage['chunks']));
            if ($min <= $pMax + 1 && $max >= $pMin - 1) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Concatenate consecutive chunks, dropping the overlap each chunk
     * repeats from the end of the previous one.
     *
     * @param  array<int, \App\Models\RagChunk>  $chunks  ordered by chunk index
     * @return string
     */
    public function merge(array $chunks): string
    {
        $text = '';
        $previousIndex = null;

        foreach ($chunks as $index => $chunk) {
            $content = $chunk->content;

            if ($text === '') {
                $text = $content;
            } elseif ($previousIndex !== null && $index === $previousIndex + 1) {
                $text .= $this->withoutOverlap($text, $content);
            } else {
                $text .= "\n\n[...]\n\n" . $content;
            }
            $previousIndex = $index;
        }

        return $text;
    }

    /**
     * The part of $next not already at the end of $previous.
     *
     * @param  string  $previous
     * @param  string  $next
     * @return string
     */
    protected function withoutOverlap(string $previous, string $next): string
    {
        // Longest suffix of $previous (within the last 600 chars) that is a
        // prefix of $next - the overlap sentences the chunker repeated.
        $tail = mb_substr($previous, -600);
        $length = mb_strlen($tail);

        for ($position = 0; $position < $length - 10; $position++) {
            $suffix = mb_substr($tail, $position);
            if (mb_substr($next, 0, mb_strlen($suffix)) === $suffix) {
                return mb_substr($next, mb_strlen($suffix));
            }
        }

        return "\n\n" . $next;
    }
}
