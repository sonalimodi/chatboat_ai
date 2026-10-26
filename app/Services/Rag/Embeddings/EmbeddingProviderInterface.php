<?php

namespace App\Services\Rag\Embeddings;

/**
 * Turns text into dense vectors for semantic retrieval. Document and query
 * embeddings are separate calls because asymmetric models (like Gemini's)
 * embed them differently for better question -> passage matching.
 */
interface EmbeddingProviderInterface
{
    /**
     * @param  array<int, string>  $texts
     * @param  string|null  $title  Optional document title, passed as context to models that support it
     * @return array<int, array<int, float>>  One unit-length vector per input, same order
     *
     * @throws \App\Exceptions\RagException
     */
    public function embedDocuments(array $texts, ?string $title = null): array;

    /**
     * @param  string  $query
     * @return array<int, float>  Unit-length vector
     *
     * @throws \App\Exceptions\RagException
     */
    public function embedQuery(string $query): array;

    /**
     * Model identifier, recorded on each document so mismatched vector
     * spaces are never compared.
     *
     * @return string
     */
    public function model(): string;

    /**
     * @return int
     */
    public function dimensions(): int;
}
