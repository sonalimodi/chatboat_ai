<?php

namespace App\Services\Rag\Ingestion;

/**
 * Splits cleaned, page-keyed text into overlapping, sentence-aligned
 * chunks that each remember their page range.
 *
 * Strategy: paragraphs -> sentences (each tagged with its page), then
 * sentences are packed greedily up to $chunkSize characters. When a chunk
 * is full, the next one starts with the trailing sentences of the previous
 * chunk (up to $overlap characters), so a fact that straddles a boundary
 * still appears whole in at least one chunk. Chunks never cut a sentence
 * in half unless a single "sentence" (e.g. a flattened table) is longer
 * than $chunkSize on its own.
 */
class TextChunker
{
    /**
     * @var int
     */
    protected $chunkSize;

    /**
     * @var int
     */
    protected $overlap;

    public function __construct(int $chunkSize = 1200, int $overlap = 200)
    {
        $this->chunkSize = max(200, $chunkSize);
        $this->overlap = max(0, min($overlap, intdiv($this->chunkSize, 2)));
    }

    /**
     * @param  array<int, string>  $pages  page number => cleaned text
     * @return array<int, array{content: string, page_start: int, page_end: int}>
     */
    public function chunk(array $pages): array
    {
        $sentences = $this->sentences($pages);

        $chunks = [];
        $current = [];
        $currentLength = 0;
        $hasNewContent = false;

        foreach ($sentences as $sentence) {
            $length = mb_strlen($sentence['text']) + 1;

            if ($currentLength + $length > $this->chunkSize && $hasNewContent) {
                $chunks[] = $this->buildChunk($current);
                $current = $this->overlapTail($current);
                $currentLength = $this->totalLength($current);
                $hasNewContent = false;
            }

            $current[] = $sentence;
            $currentLength += $length;
            $hasNewContent = true;
        }

        if ($hasNewContent) {
            $chunks[] = $this->buildChunk($current);
        }

        return $chunks;
    }

    /**
     * Flatten pages into a sentence stream. Each sentence records its page
     * range and whether it starts a new paragraph (to keep paragraph
     * breaks inside chunks). A paragraph that runs off the bottom of one
     * page and continues at the top of the next is stitched back together.
     *
     * @param  array<int, string>  $pages
     * @return array<int, array{text: string, page_start: int, page_end: int, paragraph_start: bool}>
     */
    protected function sentences(array $pages): array
    {
        $sentences = [];

        foreach ($pages as $pageNumber => $text) {
            $paragraphs = preg_split('/\n{2,}/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

            foreach ($paragraphs as $paragraphIndex => $paragraph) {
                $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph));
                if ($paragraph === '') {
                    continue;
                }

                if ($paragraphIndex === 0 && $this->continuesFromPreviousPage($sentences, $paragraph)) {
                    $last = array_pop($sentences);
                    $paragraph = $last['text'] . ' ' . $paragraph;
                    $startPage = $last['page_start'];
                    $paragraphStart = $last['paragraph_start'];
                } else {
                    $startPage = $pageNumber;
                    $paragraphStart = true;
                }

                foreach ($this->splitSentences($paragraph) as $i => $sentenceText) {
                    $sentences[] = [
                        'text' => $sentenceText,
                        'page_start' => $i === 0 ? $startPage : $pageNumber,
                        'page_end' => $pageNumber,
                        'paragraph_start' => $i === 0 && $paragraphStart,
                    ];
                }
            }
        }

        return $sentences;
    }

    /**
     * @param  array  $sentences
     * @param  string  $paragraph
     * @return bool
     */
    protected function continuesFromPreviousPage(array $sentences, string $paragraph): bool
    {
        if (empty($sentences)) {
            return false;
        }

        $last = end($sentences)['text'];

        return ! preg_match('/[.!?:;"\x{201D}\x{2019}]$/u', $last)
            && preg_match('/^\p{Ll}/u', $paragraph)
            && mb_strlen($last) + mb_strlen($paragraph) < $this->chunkSize * 4;
    }

    /**
     * Split on sentence-ending punctuation followed by whitespace and an
     * uppercase letter, digit, quote, bracket or currency sign. Common
     * abbreviations ("U.S.", "Mr.", "No.") are protected so "U.S. Code" is
     * not split. Any piece still longer than the chunk size (typically a
     * flattened table) is hard-wrapped on word boundaries.
     *
     * @param  string  $paragraph
     * @return array<int, string>
     */
    protected function splitSentences(string $paragraph): array
    {
        $protected = preg_replace_callback(
            '/\b(?:U\.S|U\.S\.C|Mr|Mrs|Ms|Dr|No|Nos|Inc|Co|Corp|Jr|Sr|St|vs|etc|e\.g|i\.e|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec|Fig|P\.L|Stat)\./u',
            function ($m) {
                return str_replace('.', "\u{2024}", $m[0]);
            },
            $paragraph
        );

        $parts = preg_split('/(?<=[.!?])["\x{201D}\x{2019})]?\s+(?=["\x{201C}\x{2018}(\[$]?[\p{Lu}\d])/u', $protected, -1, PREG_SPLIT_NO_EMPTY);

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim(str_replace("\u{2024}", '.', $part));
            if ($part === '') {
                continue;
            }
            foreach ($this->hardWrap($part) as $piece) {
                $sentences[] = $piece;
            }
        }

        return $sentences;
    }

    /**
     * @param  string  $text
     * @return array<int, string>
     */
    protected function hardWrap(string $text): array
    {
        if (mb_strlen($text) <= $this->chunkSize) {
            return [$text];
        }

        $pieces = [];
        $current = '';
        foreach (preg_split('/\s+/u', $text) as $word) {
            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($word) > $this->chunkSize) {
                $pieces[] = $current;
                $current = $word;
            } else {
                $current = $current === '' ? $word : $current . ' ' . $word;
            }
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * The trailing sentences of a chunk, totalling at most $overlap
     * characters, to seed the next chunk.
     *
     * @param  array  $sentences
     * @return array
     */
    protected function overlapTail(array $sentences): array
    {
        $tail = [];
        $length = 0;

        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $sentenceLength = mb_strlen($sentences[$i]['text']) + 1;
            if ($length + $sentenceLength > $this->overlap) {
                break;
            }
            array_unshift($tail, $sentences[$i]);
            $length += $sentenceLength;
        }

        return $tail;
    }

    /**
     * @param  array  $sentences
     * @return int
     */
    protected function totalLength(array $sentences): int
    {
        $length = 0;
        foreach ($sentences as $sentence) {
            $length += mb_strlen($sentence['text']) + 1;
        }

        return $length;
    }

    /**
     * @param  array  $sentences
     * @return array{content: string, page_start: int, page_end: int}
     */
    protected function buildChunk(array $sentences): array
    {
        $content = '';
        foreach ($sentences as $i => $sentence) {
            if ($i === 0) {
                $content = $sentence['text'];
            } else {
                $content .= ($sentence['paragraph_start'] ? "\n\n" : ' ') . $sentence['text'];
            }
        }

        return [
            'content' => $content,
            'page_start' => min(array_column($sentences, 'page_start')),
            'page_end' => max(array_column($sentences, 'page_end')),
        ];
    }
}
