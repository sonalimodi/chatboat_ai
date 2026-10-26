<?php

namespace App\Services\Rag\Retrieval;

use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Services\Rag\Embeddings\EmbeddingProviderInterface;
use App\Services\Rag\VectorMath;
use Illuminate\Support\Collection;

/**
 * Hybrid dense + lexical retrieval over all ingested chunks.
 *
 *  1. Dense: cosine similarity between the query embedding and every
 *     chunk embedding (vectors are unit length, so a dot product).
 *  2. Lexical: BM25 over the chunk texts.
 *  3. Each ranking is cut to `candidates`, then fused with weighted
 *     Reciprocal Rank Fusion: score = sum(w / (k + rank)). RRF only uses
 *     ranks, so the two very different score scales never need
 *     calibrating against each other.
 */
class HybridRetriever
{
    /**
     * @var \App\Services\Rag\Embeddings\EmbeddingProviderInterface
     */
    protected $embeddings;

    /**
     * Chunks (with decoded vectors) loaded once per instance - one HTTP
     * request or one CLI run.
     *
     * @var \Illuminate\Support\Collection|null
     */
    protected $corpus;

    /**
     * The corpus keyed by "documentId:chunkIndex", for neighbour lookups.
     *
     * @var \Illuminate\Support\Collection|null
     */
    protected $byPosition;

    public function __construct(EmbeddingProviderInterface $embeddings)
    {
        $this->embeddings = $embeddings;
    }

    /**
     * BM25 indexes, per corpus subset, built on first use.
     *
     * @var array<string, \App\Services\Rag\Retrieval\Bm25Index>
     */
    protected $lexicalIndexes = [];

    /**
     * @param  string  $query
     * @param  int|null  $topK
     * @param  array<int, int>|null  $documentIds  restrict to these documents (metadata filter)
     * @return array<int, array{chunk: \App\Models\RagChunk, score: float, dense_rank: int|null, dense_score: float|null, lexical_rank: int|null, reference_rank: int|null}>
     */
    public function retrieve(string $query, ?int $topK = null, ?array $documentIds = null): array
    {
        $corpus = $this->corpus();

        if ($documentIds !== null) {
            $corpus = $corpus->whereIn('rag_document_id', $documentIds)->values();
        }

        if ($corpus->isEmpty()) {
            return [];
        }

        $indexKey = $documentIds === null ? '*' : implode(',', $documentIds);
        if (! isset($this->lexicalIndexes[$indexKey])) {
            $this->lexicalIndexes[$indexKey] = $this->lexicalIndex($corpus);
        }

        return $this->rank(
            $corpus,
            $query,
            $this->embeddings->embedQuery($query),
            $topK ?? (int) config('rag.top_k'),
            $this->lexicalIndexes[$indexKey]
        );
    }

    /**
     * The chunks immediately before/after a chunk within the same section
     * (structured documents only), for context expansion.
     *
     * @param  \App\Models\RagChunk  $chunk
     * @param  int  $window
     * @return array<int, \App\Models\RagChunk>  chunk_index => chunk, in order, including the chunk itself
     */
    public function neighbors(RagChunk $chunk, int $window): array
    {
        $result = [$chunk->chunk_index => $chunk];
        if ($chunk->rag_section_id === null || $window < 1) {
            return $result;
        }

        if ($this->byPosition === null) {
            $this->byPosition = $this->corpus()->keyBy(function (RagChunk $c) {
                return $c->rag_document_id . ':' . $c->chunk_index;
            });
        }

        foreach ([-1, 1] as $direction) {
            for ($step = 1; $step <= $window; $step++) {
                $neighbor = $this->byPosition[$chunk->rag_document_id . ':' . ($chunk->chunk_index + $direction * $step)] ?? null;
                if ($neighbor === null || $neighbor->rag_section_id !== $chunk->rag_section_id) {
                    break;
                }
                $result[$neighbor->chunk_index] = $neighbor;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * Element references in a question: "Box 3.1", "table 2.10",
     * "Figure B1.2.1", "Annex 4.2", "Chapter 3".
     *
     * @param  string  $query
     * @return array<int, string>  normalised labels, e.g. ["Box 3.1"]
     */
    public static function elementReferences(string $query): array
    {
        preg_match_all('/\b(box|figure|fig\.|table|annex|chapter)\s+([A-Z]{0,2}\d+(?:\.\d+)*)/iu', $query, $m, PREG_SET_ORDER);

        $labels = [];
        foreach ($m as $match) {
            $kind = strtolower($match[1]) === 'fig.' ? 'Figure' : ucfirst(strtolower($match[1]));
            $labels[] = $kind . ' ' . strtoupper($match[2]);
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  \Illuminate\Support\Collection  $chunks
     * @return \App\Services\Rag\Retrieval\Bm25Index
     */
    protected function lexicalIndex(Collection $chunks): Bm25Index
    {
        return new Bm25Index($chunks->keyBy('id')->map(function (RagChunk $chunk) {
            return $chunk->searchableText();
        })->all());
    }

    /**
     * Rank a given set of chunks against a query - the pure retrieval
     * logic, separated from loading and embedding so it can be tested.
     *
     * @param  \Illuminate\Support\Collection  $chunks  RagChunk models, each with a decoded `vector` attribute
     * @param  string  $query
     * @param  array<int, float>  $queryVector
     * @param  int  $topK
     * @param  \App\Services\Rag\Retrieval\Bm25Index|null  $lexical  prebuilt index over the same chunks
     * @return array
     */
    public function rank(Collection $chunks, string $query, array $queryVector, int $topK, ?Bm25Index $lexical = null): array
    {
        $candidates = max($topK, (int) config('rag.candidates', 20));
        $byId = $chunks->keyBy('id');

        $denseScores = [];
        foreach ($byId as $id => $chunk) {
            $denseScores[$id] = VectorMath::dot($queryVector, $chunk->getAttribute('vector'));
        }
        arsort($denseScores);
        $allDense = $denseScores;
        $denseScores = array_slice($denseScores, 0, $candidates, true);

        $lexicalScores = ($lexical ?: $this->lexicalIndex($chunks))->search($query, $candidates);

        $rankings = [array_keys($denseScores), array_keys($lexicalScores)];
        $weights = [(float) config('rag.dense_weight', 1.0), (float) config('rag.lexical_weight', 1.0)];

        // Explicit element references ("Box 3.1", "Table 2.10") add a third
        // ranking: that element's own chunks, best dense match first.
        $referenced = [];
        foreach (static::elementReferences($query) as $label) {
            foreach (array_keys($allDense) as $id) {
                if (stripos((string) $byId[$id]->section_path, $label . ':') !== false) {
                    $referenced[$id] = true;
                }
            }
        }
        $referenced = array_slice(array_keys($referenced), 0, $candidates);
        if (! empty($referenced)) {
            $rankings[] = $referenced;
            $weights[] = (float) config('rag.reference_weight', 2.0);
        }

        $fused = static::reciprocalRankFusion($rankings, $weights, (int) config('rag.rrf_k', 60));

        $selected = static::selectWithGuarantee(
            $fused,
            $rankings,
            (int) config('rag.guaranteed_per_retriever', 2),
            $topK
        );

        $denseRanks = array_flip(array_keys($denseScores));
        $lexicalRanks = array_flip(array_keys($lexicalScores));
        $referenceRanks = array_flip($referenced);

        $results = [];
        foreach ($selected as $id => $score) {
            $results[] = [
                'chunk' => $byId[$id],
                'score' => $score,
                'dense_rank' => isset($denseRanks[$id]) ? $denseRanks[$id] + 1 : null,
                'dense_score' => $denseScores[$id] ?? null,
                'lexical_rank' => isset($lexicalRanks[$id]) ? $lexicalRanks[$id] + 1 : null,
                'reference_rank' => isset($referenceRanks[$id]) ? $referenceRanks[$id] + 1 : null,
            ];
        }

        return $results;
    }

    /**
     * Weighted Reciprocal Rank Fusion (Cormack et al., 2009).
     *
     * @param  array<int, array<int, int|string>>  $rankings  each an ordered list of ids, best first
     * @param  array<int, float>  $weights  one per ranking
     * @param  int  $k  dampening constant; 60 is the standard choice
     * @return array<int|string, float>  id => fused score, best first
     */
    public static function reciprocalRankFusion(array $rankings, array $weights, int $k = 60): array
    {
        $scores = [];

        foreach ($rankings as $i => $ids) {
            $weight = $weights[$i] ?? 1.0;
            foreach (array_values($ids) as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + $weight / ($k + $rank + 1);
            }
        }

        arsort($scores);

        return $scores;
    }

    /**
     * Take the top $topK fused ids, but first reserve slots for the top
     * $guaranteed ids of every individual ranking. RRF rewards chunks that
     * appear in *both* lists, so a chunk that only one retriever finds -
     * even at rank 1, e.g. a paraphrase with no shared keywords ("how
     * many people work there" vs "employed 1,662 staff") - can otherwise
     * be outvoted by mediocre chunks that both retrievers rank moderately.
     *
     * @param  array<int|string, float>  $fused  id => fused score, best first
     * @param  array<int, array<int, int|string>>  $rankings
     * @param  int  $guaranteed
     * @param  int  $topK
     * @return array<int|string, float>  id => fused score, best first
     */
    public static function selectWithGuarantee(array $fused, array $rankings, int $guaranteed, int $topK): array
    {
        $selected = [];

        foreach ($rankings as $ids) {
            foreach (array_slice(array_values($ids), 0, $guaranteed) as $id) {
                if (count($selected) < $topK && isset($fused[$id])) {
                    $selected[$id] = $fused[$id];
                }
            }
        }

        foreach ($fused as $id => $score) {
            if (count($selected) >= $topK) {
                break;
            }
            $selected[$id] = $score;
        }

        arsort($selected);

        return $selected;
    }

    /**
     * Every chunk from documents embedded with the currently configured
     * model, with vectors decoded once.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function corpus(): Collection
    {
        if ($this->corpus !== null) {
            return $this->corpus;
        }

        $documentIds = RagDocument::where('embedding_model', $this->embeddings->model())
            ->where('embedding_dimensions', $this->embeddings->dimensions())
            ->pluck('id');

        $this->corpus = RagChunk::with('document')
            ->whereIn('rag_document_id', $documentIds)
            ->get()
            ->each(function (RagChunk $chunk) {
                $chunk->setAttribute('vector', $chunk->vector());
            });

        return $this->corpus;
    }
}
