<?php

namespace App\Services\Rag\Ingestion;

/**
 * Turns raw per-page PDF text into clean, paragraph-separated text:
 *
 *  - normalises ligatures, non-breaking spaces and dot leaders;
 *  - removes running headers/footers (short lines at the top or bottom of
 *    a page that repeat across many pages, e.g. "2019 ANNUAL REPORT | U.S.
 *    GOVERNMENT PUBLISHING OFFICE") and bare page numbers, which would otherwise pollute every chunk and
 *    skew both embeddings and keyword scores;
 *  - re-joins lines that PDF layout wrapped mid-sentence (including the
 *    "one line, blank line, one line" pattern multi-column pages produce).
 *
 * Output keeps the same page keys; paragraphs are separated by "\n\n".
 */
class TextCleaner
{
    /**
     * A line must appear on at least this many pages...
     */
    const MIN_REPEAT_PAGES = 3;

    /**
     * ...and on at least this fraction of all pages to count as a running
     * header/footer.
     */
    const MIN_REPEAT_RATIO = 0.05;

    /**
     * Longer lines are body text, never headers - even if repeated.
     */
    const MAX_HEADER_LENGTH = 120;

    /**
     * Only this many non-empty lines at the top and at the bottom of each
     * page are header/footer candidates. Restricting to the page edges is
     * what makes digit-wildcarded matching safe: body lines (or table
     * labels like "Total") that happen to repeat are never touched.
     */
    const EDGE_LINES = 4;

    /**
     * Running header/footer keys found by the last stripPageFurniture().
     *
     * @var array<int, string>
     */
    protected $lastRepeatedKeys = [];

    /**
     * @param  array<int, string>  $pages  page number => raw text
     * @return array<int, string>  page number => cleaned text
     */
    public function cleanPages(array $pages): array
    {
        $cleaned = [];
        foreach ($this->stripPageFurniture($pages) as $pageNumber => $lines) {
            $cleaned[$pageNumber] = $this->joinParagraphs($lines);
        }

        return $cleaned;
    }

    /**
     * Normalise each page and drop its running headers/footers and page
     * numbers, but keep the remaining lines separate (empty lines
     * included) - structure detection needs line-level access to spot
     * headings and captions before paragraphs are rebuilt.
     *
     * @param  array<int, string>  $pages
     * @return array<int, array<int, string>>  page number => lines
     */
    public function stripPageFurniture(array $pages): array
    {
        $linesByPage = [];
        foreach ($pages as $pageNumber => $text) {
            $linesByPage[$pageNumber] = array_map('trim', explode("\n", $this->normalize($text)));
        }

        $repeated = $this->findRepeatedLines($linesByPage);
        $this->lastRepeatedKeys = array_keys($repeated);

        $stripped = [];
        foreach ($linesByPage as $pageNumber => $lines) {
            $edges = $this->edgeLineIndexes($lines);
            $kept = [];
            foreach ($lines as $index => $line) {
                if (isset($edges[$index]) && (isset($repeated[$this->lineKey($line)]) || $this->isPageNumber($line))) {
                    continue;
                }
                $kept[] = $line;
            }
            $stripped[$pageNumber] = $kept;
        }

        return $stripped;
    }

    /**
     * Whether a line from another extraction mode of the same document
     * (e.g. "-table" mode, which merges header pieces onto one line)
     * contains a running header/footer found by the last
     * stripPageFurniture() call.
     *
     * @param  string  $line
     * @return bool
     */
    public function containsRunningHeader(string $line): bool
    {
        $key = $this->lineKey(preg_replace('/\s+/u', ' ', trim($line)));
        foreach ($this->lastRepeatedKeys as $repeated) {
            if (mb_strlen($repeated) >= 10 && mb_strpos($key, $repeated) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string  $text
     * @return string
     */
    public function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $text = strtr($text, [
            "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl',
            "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl',
            "\u{00A0}" => ' ', "\u{2007}" => ' ', "\u{202F}" => ' ',
            "\u{00AD}" => '', "\u{200B}" => '', "\u{FEFF}" => '',
            "\t" => ' ',
        ]);

        // Table-of-contents dot leaders: "Mission.............4"
        $text = preg_replace('/(?:\.\s?){4,}|(?:\x{2026}\s?){2,}/u', ' ', $text);

        // Collapse runs of spaces within a line (layout padding).
        $text = preg_replace('/[ ]{2,}/', ' ', $text);

        return $text;
    }

    /**
     * Lines (compared case-insensitively, with digits wildcarded so
     * "Page 3" and "Page 4" match) that repeat across enough pages to be
     * running headers or footers.
     *
     * @param  array<int, array<int, string>>  $linesByPage
     * @return array<string, true>
     */
    protected function findRepeatedLines(array $linesByPage): array
    {
        $pageCount = count($linesByPage);
        $threshold = max(self::MIN_REPEAT_PAGES, (int) ceil($pageCount * self::MIN_REPEAT_RATIO));

        if ($pageCount < $threshold) {
            return [];
        }

        $pagesPerKey = [];
        foreach ($linesByPage as $lines) {
            $seenOnThisPage = [];
            foreach (array_keys($this->edgeLineIndexes($lines)) as $index) {
                $line = $lines[$index];
                // Running headers/footers are labels, not sentences.
                if (mb_strlen($line) > self::MAX_HEADER_LENGTH || preg_match('/[.!?]$/u', $line)) {
                    continue;
                }
                // Needs a real word - lines like "2019 2018" are table
                // column headings, which carry meaning for the rows below.
                if (! preg_match('/\pL{3,}/u', $line)) {
                    continue;
                }
                // Parenthetical unit notes - "(Dollars in Thousands)" - repeat
                // on every financial table but are what make its figures
                // interpretable, so they're kept.
                if (preg_match('/^\(.*\)$/u', $line)) {
                    continue;
                }
                $seenOnThisPage[$this->lineKey($line)] = true;
            }
            foreach (array_keys($seenOnThisPage) as $key) {
                $pagesPerKey[$key] = ($pagesPerKey[$key] ?? 0) + 1;
            }
        }

        $repeated = [];
        foreach ($pagesPerKey as $key => $count) {
            if ($count >= $threshold) {
                $repeated[$key] = true;
            }
        }

        return $repeated;
    }

    /**
     * Indexes of the first and last EDGE_LINES non-empty lines of a page.
     *
     * @param  array<int, string>  $lines
     * @return array<int, true>
     */
    protected function edgeLineIndexes(array $lines): array
    {
        $nonEmpty = array_keys(array_filter($lines, function ($line) {
            return $line !== '';
        }));

        $edges = array_merge(
            array_slice($nonEmpty, 0, self::EDGE_LINES),
            array_slice($nonEmpty, -self::EDGE_LINES)
        );

        return array_fill_keys($edges, true);
    }

    /**
     * @param  string  $line
     * @return string
     */
    protected function lineKey(string $line): string
    {
        $key = mb_strtolower($line);
        $key = preg_replace('/\d+/', '#', $key);

        return preg_replace('/\s+/u', ' ', $key);
    }

    /**
     * "12", "- 12 -", "Page 12", "Page 12 of 80".
     *
     * @param  string  $line
     * @return bool
     */
    protected function isPageNumber(string $line): bool
    {
        return (bool) preg_match('/^(?:page\s+)?[-\x{2013}\x{2014}]?\s*\d{1,4}\s*[-\x{2013}\x{2014}]?(?:\s+of\s+\d{1,4})?$/iu', $line);
    }

    /**
     * Rebuild paragraphs from layout lines. A blank line normally ends a
     * paragraph, and so does a line ending in terminal punctuation. A
     * line is glued onto the previous one when the previous line stops
     * mid-sentence and this one continues it (starts lowercase / with a
     * digit or opening bracket) - even across blank lines, which is how
     * narrow multi-column layouts come out of pdftotext.
     *
     * @param  array<int, string>  $lines
     * @return string
     */
    public function joinParagraphs(array $lines): string
    {
        $paragraphs = [];
        $current = '';

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if ($current !== '' && $this->continuesPrevious($current, $line)) {
                if (preg_match('/\pL-$/u', $current) && preg_match('/^\p{Ll}/u', $line)) {
                    // End-of-line hyphenation: "imple-" + "mentation".
                    $current = mb_substr($current, 0, -1) . $line;
                } else {
                    $current .= ' ' . $line;
                }
                continue;
            }

            if ($current !== '') {
                $paragraphs[] = $current;
            }
            $current = $line;
        }

        if ($current !== '') {
            $paragraphs[] = $current;
        }

        return implode("\n\n", $paragraphs);
    }

    /**
     * @param  string  $previous
     * @param  string  $line
     * @return bool
     */
    protected function continuesPrevious(string $previous, string $line): bool
    {
        if (preg_match('/[.!?:;"\x{201D}\x{2019}]$/u', $previous)) {
            return false;
        }

        return (bool) preg_match('/^[\p{Ll}\d(\[$%\x{2014}\x{2013}]/u', $line);
    }
}
