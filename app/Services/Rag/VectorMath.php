<?php

namespace App\Services\Rag;

/**
 * Small, dependency-free vector helpers. The corpus for a single report is
 * a few hundred chunks, so exact (brute-force) cosine search in PHP takes a
 * few milliseconds and needs no vector database. Swap HybridRetriever's
 * dense step for pgvector / a vector DB if the corpus grows to many
 * thousands of chunks.
 */
class VectorMath
{
    /**
     * Scale a vector to unit length, so cosine similarity becomes a plain
     * dot product. Gemini only pre-normalises full 3072-dim vectors; any
     * truncated output must be normalised by the caller.
     *
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    public static function normalize(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);

        if ($norm == 0.0) {
            return $vector;
        }

        $out = [];
        foreach ($vector as $v) {
            $out[] = $v / $norm;
        }

        return $out;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return float
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * Encode as base64 of packed little-endian float32s, for storage.
     *
     * @param  array<int, float>  $vector
     * @return string
     */
    public static function pack(array $vector): string
    {
        return base64_encode(pack('g*', ...$vector));
    }

    /**
     * @param  string  $packed
     * @return array<int, float>
     */
    public static function unpack(string $packed): array
    {
        $binary = base64_decode($packed, true);

        if ($binary === false || $binary === '') {
            return [];
        }

        return array_values(unpack('g*', $binary));
    }
}
