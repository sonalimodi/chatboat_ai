<?php

namespace App\Services\Rag\Structure;

/**
 * Reads a printed table of contents ("Title ........ 47") from the
 * layout-mode text of a document's first pages.
 *
 * Returns section entries in order, each with its printed page label and
 * the column its title starts at (indentation is how a TOC shows nesting).
 * Lists of boxes/figures/tables/annexes are skipped - those elements are
 * found from their captions in the body instead. When a document has
 * both a "Summary of Contents" and a full "Contents", the longest run of
 * consecutive TOC pages wins.
 */
class TocParser
{
    /**
     * How many leading pages to scan for a TOC.
     */
    const SCAN_PAGES = 30;

    /**
     * A page needs at least this many entries to count as a TOC page.
     */
    const MIN_ENTRIES_PER_PAGE = 4;

    /**
     * @param  array<int, string>  $layoutPages  pdf page number => layout-mode text
     * @return array<int, array{title: string, page_label: string, indent: int, toc_page: int}>
     */
    public function parse(array $layoutPages): array
    {
        $byPage = [];
        foreach (array_slice($layoutPages, 0, self::SCAN_PAGES, true) as $pageNumber => $text) {
            $entries = $this->entriesOnPage($text, $pageNumber);
            if (count($entries) >= self::MIN_ENTRIES_PER_PAGE) {
                $byPage[$pageNumber] = $entries;
            }
        }

        // Group into runs of consecutive TOC pages (blank pages between
        // allowed), keep the run with the most entries.
        $runs = [];
        $current = [];
        $lastPage = null;
        foreach ($byPage as $pageNumber => $entries) {
            // A new run starts after a gap, or when the list starts over
            // (a "Summary of Contents" followed by the full "Contents").
            $restarts = ! empty($current) && $entries[0]['title'] === $current[0]['title'];
            if ($lastPage !== null && ($pageNumber - $lastPage > 2 || $restarts)) {
                $runs[] = $current;
                $current = [];
            }
            $current = array_merge($current, $entries);
            $lastPage = $pageNumber;
        }
        if (! empty($current)) {
            $runs[] = $current;
        }

        $best = [];
        foreach ($runs as $run) {
            if (count($run) > count($best)) {
                $best = $run;
            }
        }

        return $best;
    }

    /**
     * @param  string  $text
     * @param  int  $pageNumber
     * @return array
     */
    protected function entriesOnPage(string $text, int $pageNumber): array
    {
        $entries = [];
        $pending = null;
        $inElementList = false;

        foreach (explode("\n", $text) as $line) {
            $line = $this->blankMarginLabels(rtrim($line));
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*(Boxes|Figures|Tables|Annexes|Maps)\b/u', $line)) {
                // Everything after a "Boxes / Figures / Tables" list heading
                // on this page is an element list, not sections.
                $inElementList = true;
            }

            $entry = $this->parseEntry($line);

            if ($entry === null) {
                // A title wrapped onto two lines: the first line has no
                // leader/page number; keep it to prefix the next entry.
                $trimmed = trim($line);
                $pending = (mb_strlen($trimmed) <= 120 && ! preg_match('/^(Summary of )?Contents$/i', $trimmed))
                    ? ['title' => $trimmed, 'indent' => strlen($line) - strlen(ltrim($line))]
                    : null;
                continue;
            }

            if ($pending !== null) {
                $entry['title'] = $pending['title'] . ' ' . $entry['title'];
                $entry['indent'] = $pending['indent'];
                $pending = null;
            }

            if ($inElementList || $this->isElementEntry($entry['title'])) {
                continue;
            }

            $entry['toc_page'] = $pageNumber;
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * "   Global trade .......... 13" => title, page label, indent.
     *
     * @param  string  $line
     * @return array|null
     */
    protected function parseEntry(string $line): ?array
    {
        $normalized = str_replace("\u{2026}", '...', $line);

        if (! preg_match('/^(\s*)(\S.*?)\s*(?:\.\s*){3,}\s*(\d{1,4}|[ivxlcdm]{1,7})\s*$/iu', $normalized, $m)) {
            return null;
        }

        $title = trim(preg_replace('/\s+/u', ' ', $m[2]));
        if ($title === '' || mb_strlen($title) > 200) {
            return null;
        }

        return [
            'title' => $title,
            'page_label' => strtolower($m[3]),
            'indent' => strlen($m[1]),
        ];
    }

    /**
     * Side-tab labels ("Chapter 2") and margin page numbers ("viii") share
     * lines with real entries in layout mode. They're replaced with spaces
     * of the same width so the entry's indentation is preserved.
     *
     * @param  string  $line
     * @return string
     */
    protected function blankMarginLabels(string $line): string
    {
        return preg_replace_callback('/^(\s*)((?:Chapter|Part)\s+\d+|[ivxlc]{1,6}|\d{1,3})(\s{2,})(?=\S)/iu', function ($m) {
            return $m[1] . str_repeat(' ', strlen($m[2])) . $m[3];
        }, $line);
    }

    /**
     * Entries from box/figure/table/annex lists: "Box 1.1 ...", "1.4 Commodity
     * markets", "B1.2.1 Investment trends", "A4.2.1 ...".
     *
     * @param  string  $title
     * @return bool
     */
    protected function isElementEntry(string $title): bool
    {
        return (bool) preg_match('/^((Box|Figure|Table|Annex|Map)\s+)?[A-Z]{0,2}\d+(\.\d+)+\s/u', $title);
    }
}
