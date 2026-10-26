<?php

namespace App\Services\Rag\Retrieval;

/**
 * In-memory Okapi BM25 keyword index - the lexical half of hybrid
 * retrieval. It catches what embeddings tend to blur: exact figures
 * ("1,662"), years, acronyms ("FDLP", "TDR") and proper nouns.
 *
 * Built per request from the chunk texts; at a few hundred chunks this
 * takes a few milliseconds, so no persistent inverted index is needed.
 */
class Bm25Index
{
    const K1 = 1.5;

    const B = 0.75;

    /**
     * Function words that carry no retrieval signal. Kept short on
     * purpose: domain words like "report" or "office" still matter for
     * ranking via IDF.
     */
    const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'but', 'by', 'can', 'did', 'do', 'does', 'for',
        'from', 'had', 'has', 'have', 'how', 'i', 'in', 'into', 'is', 'it', 'its', 'many', 'much', 'of', 'on',
        'or', 'our', 'so', 'that', 'the', 'their', 'there', 'these', 'this', 'those', 'to', 'was', 'we', 'were',
        'what', 'when', 'where', 'which', 'who', 'whom', 'why', 'will', 'with', 'you', 'your', 'about', 'tell',
        'me', 'please', 'according', 'any', 'all', 'also', 'than', 'then', 'they', 'them', 'he', 'she', 'his',
        'her', 'not', 'no', 'if', 'would', 'could', 'should', 'may', 'more', 'most',
    ];

    /**
     * @var array<int|string, array<string, int>>  doc id => term => frequency
     */
    protected $termFrequencies = [];

    /**
     * @var array<int|string, int>
     */
    protected $lengths = [];

    /**
     * @var array<string, int>  term => number of docs containing it
     */
    protected $documentFrequencies = [];

    /**
     * @var float
     */
    protected $averageLength = 0.0;

    /**
     * @var array<string, true>
     */
    protected static $stopwordSet;

    /**
     * @param  array<int|string, string>  $documents  id => text
     */
    public function __construct(array $documents)
    {
        foreach ($documents as $id => $text) {
            $tokens = static::tokenize($text);
            $frequencies = array_count_values($tokens);

            $this->termFrequencies[$id] = $frequencies;
            $this->lengths[$id] = count($tokens);

            foreach (array_keys($frequencies) as $term) {
                $this->documentFrequencies[$term] = ($this->documentFrequencies[$term] ?? 0) + 1;
            }
        }

        $this->averageLength = count($this->lengths) > 0
            ? array_sum($this->lengths) / count($this->lengths)
            : 0.0;
    }

    /**
     * @param  string  $query
     * @param  int  $limit
     * @return array<int|string, float>  doc id => score, best first, zero scores omitted
     */
    public function search(string $query, int $limit): array
    {
        $queryTerms = array_unique(static::tokenize($query));
        $documentCount = count($this->lengths);
        $scores = [];

        foreach ($queryTerms as $term) {
            $df = $this->documentFrequencies[$term] ?? 0;
            if ($df === 0) {
                continue;
            }

            // BM25+ style IDF floor: never negative for very common terms.
            $idf = log(1 + ($documentCount - $df + 0.5) / ($df + 0.5));

            foreach ($this->termFrequencies as $id => $frequencies) {
                if (! isset($frequencies[$term])) {
                    continue;
                }
                $tf = $frequencies[$term];
                $norm = 1 - self::B + self::B * ($this->lengths[$id] / max($this->averageLength, 1e-9));
                $scores[$id] = ($scores[$id] ?? 0.0) + $idf * ($tf * (self::K1 + 1)) / ($tf + self::K1 * $norm);
            }
        }

        arsort($scores);

        return array_slice($scores, 0, $limit, true);
    }

    /**
     * Lowercase word/number tokens. Thousands separators are dropped so
     * "1,662" and "1662" match; decimals are kept intact ("15.2"). A light
     * plural/possessive strip lets "passports" match "passport".
     *
     * @param  string  $text
     * @return array<int, string>
     */
    public static function tokenize(string $text): array
    {
        if (static::$stopwordSet === null) {
            static::$stopwordSet = array_fill_keys(self::STOPWORDS, true);
        }

        $text = mb_strtolower($text);
        $text = preg_replace('/(?<=\d),(?=\d{3}\b)/u', '', $text);
        $text = str_replace(["\u{2019}s", "'s"], '', $text);

        preg_match_all('/[\p{L}\p{N}]+(?:\.\d+)?/u', $text, $matches);

        $tokens = [];
        foreach ($matches[0] as $token) {
            if (isset(static::$stopwordSet[$token])) {
                continue;
            }
            $tokens[] = static::stem($token);
        }

        return $tokens;
    }

    /**
     * @param  string  $token
     * @return string
     */
    protected static function stem(string $token): string
    {
        $length = strlen($token);

        if ($length > 4 && substr($token, -3) === 'ies') {
            return substr($token, 0, -3) . 'y';
        }
        if ($length > 3 && substr($token, -1) === 's' && substr($token, -2) !== 'ss' && ! ctype_digit($token)) {
            return substr($token, 0, -1);
        }

        return $token;
    }
}
