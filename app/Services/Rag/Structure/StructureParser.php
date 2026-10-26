<?php

namespace App\Services\Rag\Structure;

use App\Services\Rag\Ingestion\TextCleaner;

/**
 * Recovers a long report's structure from its extracted text and emits it
 * as an ordered stream of blocks for the StructuredChunker:
 *
 *   ['type' => 'section', 'level', 'kind', 'label', 'title', 'page']   opens a heading
 *   ['type' => 'text',    'page', 'text']                              body paragraphs
 *   ['type' => 'table',   'page', 'label', 'caption', 'subtitle', 'rows', 'notes']
 *   ['type' => 'figure',  'page', 'label', 'title', 'text']
 *
 * Where the structure comes from:
 *  - Chapters / parts: divider pages ("CHAPTER 2 / REGIONAL OUTLOOKS",
 *    "STATISTICAL APPENDIX").
 *  - Sections and subsections: the printed table of contents. Each entry
 *    is located in the body in TOC order (which is what tells apart the
 *    many identical "Outlook" / "Recent developments" headings), near the
 *    page it says. Nesting depth comes from the entry's TOC indentation.
 *  - Boxes, figures, tables, annexes: their captions ("BOX 3.1 ...",
 *    "FIGURE 2.7 ...", "TABLE 2.10 ..."). Boxes have no TOC sub-entries,
 *    so short heading-like lines inside them become box subsections.
 *  - Printed page numbers: the consistent offset between PDF page numbers
 *    and the page-number lines in running headers/footers.
 *
 * Tables are rebuilt from pdftotext "-table" mode (rows intact); figures
 * keep their caption, key-finding text, panel titles and notes, and drop
 * the axis labels and tick values that charts dump into the text.
 */
class StructureParser
{
    const CAPTION = '/^(BOX|FIGURE|TABLE|ANNEX)\s+([A-Z]{0,2}\d+(?:\.\d+)*)\.?\s*(.*)$/u';

    /**
     * @var \App\Services\Rag\Ingestion\TextCleaner
     */
    protected $cleaner;

    /**
     * @var \App\Services\Rag\Structure\TableParser
     */
    protected $tables;

    /**
     * Diagnostics from the last parse (for --dry-run output).
     *
     * @var array
     */
    public $stats = [];

    /**
     * @var array<int, string|null>  pdf page => printed page label
     */
    public $pageLabels = [];

    public function __construct(TextCleaner $cleaner, TableParser $tables)
    {
        $this->cleaner = $cleaner;
        $this->tables = $tables;
    }

    /**
     * @param  array<int, string>  $rawPages  reading-order text (already glyph-repaired)
     * @param  array<int, string>|null  $tablePages  "-table" mode text, same pages
     * @param  array<int, array>  $toc  entries from TocParser
     * @return array<int, array>  blocks
     */
    public function parse(array $rawPages, ?array $tablePages, array $toc): array
    {
        $this->stats = ['toc_entries' => count($toc), 'headings_matched' => 0, 'headings_skipped' => 0,
            'chapters' => 0, 'boxes' => 0, 'figures' => 0, 'tables' => 0, 'table_pages' => 0, 'junk_lines' => 0];

        $offset = $this->pageOffset($rawPages);
        $this->pageLabels = [];
        foreach (array_keys($rawPages) as $page) {
            $this->pageLabels[$page] = ($offset !== null && $page - $offset >= 1) ? (string) ($page - $offset) : null;
        }

        $dividers = $this->dividers($rawPages, $toc);
        $entries = $this->assignLevels($toc, $dividers);
        $matchable = array_values(array_filter($entries, function ($e) {
            // Dividers are their own pages; only real headings are matched.
            return ! $e['anchor_divider'];
        }));

        $lastTocPage = empty($toc) ? 0 : max(array_column($toc, 'toc_page'));
        $pages = $this->cleaner->stripPageFurniture($rawPages);

        $blocks = [];
        $pointer = 0;
        $sectionLevel = 0;      // level of the innermost TOC/divider section
        $openBox = null;        // ['label' => 'Box 1.1', 'level' => n]
        $consumedTables = [];

        foreach ($pages as $page => $lines) {
            // TOC pages, and the lists of figures/tables that follow them.
            if ($page <= $lastTocPage || preg_match_all('/(?:\.\s?){4,}\s*(\d{1,4}|[ivxlc]{1,6})\s*$/mu', $rawPages[$page]) >= 4) {
                continue;
            }

            if (isset($dividers[$page])) {
                $divider = $dividers[$page];
                $blocks[] = ['type' => 'section', 'level' => 1, 'kind' => $divider['kind'], 'label' => $divider['label'],
                    'title' => $divider['title'], 'page' => $page];
                $sectionLevel = 1;
                $openBox = null;
                $this->stats['chapters']++;
                continue;
            }

            $lines = array_values(array_filter($lines, function ($l) {
                return $l !== '';
            }));

            // A box spans pages only while each page repeats its caption
            // with "(continued)".
            if ($openBox !== null && ! $this->pageContinuesBox($lines, $openBox['label'])) {
                $blocks[] = ['type' => 'close', 'level' => $openBox['level']];
                $openBox = null;
            }

            if ($tablePages !== null && $this->isWholePageTable($lines, $tablePages[$page] ?? '')) {
                $blocks[] = $this->wholePageTable($tablePages[$page], $page);
                $this->stats['table_pages']++;
                continue;
            }

            $text = [];
            $flush = function () use (&$text, &$blocks, $page) {
                if (! empty($text)) {
                    $blocks[] = ['type' => 'text', 'page' => $page, 'text' => $this->cleaner->joinParagraphs($text)];
                    $text = [];
                }
            };

            $count = count($lines);
            for ($i = 0; $i < $count; $i++) {
                $line = $lines[$i];

                // ---- Captions: boxes, annexes, tables, figures ----
                if (preg_match(self::CAPTION, $line, $m) && mb_strlen($line) <= 160 && ! preg_match('/[.;]$/u', $m[3])) {
                    $kind = strtolower($m[1]);
                    $label = ucfirst($kind) . ' ' . $m[2];
                    $title = trim($m[3]);

                    if ($kind === 'box') {
                        if (preg_match('/\(continued\)$/iu', $title)) {
                            continue;
                        }
                        $flush();
                        if ($openBox !== null) {
                            $blocks[] = ['type' => 'close', 'level' => $openBox['level']];
                        }
                        $openBox = ['label' => $label, 'level' => $sectionLevel + 1];
                        $blocks[] = ['type' => 'section', 'level' => $openBox['level'], 'kind' => 'box', 'label' => $label,
                            'title' => $title, 'page' => $page];
                        $this->stats['boxes']++;
                        continue;
                    }

                    if ($kind === 'annex') {
                        if (preg_match('/\(continued\)$/iu', $title)) {
                            continue;
                        }
                        $flush();
                        // Wrapped title: "ANNEX 4.3 Commodity" + "price cycles".
                        if (isset($lines[$i + 1]) && mb_strlen($lines[$i + 1]) <= 60 && preg_match('/^\p{Ll}/u', $lines[$i + 1])) {
                            $title .= ' ' . $lines[++$i];
                        }
                        $openBox = null;
                        $sectionLevel = 2;
                        $blocks[] = ['type' => 'section', 'level' => 2, 'kind' => 'annex', 'label' => $label,
                            'title' => $title, 'page' => $page];
                        continue;
                    }

                    if ($kind === 'table') {
                        $flush();
                        $i = $this->skipCaptionedRegion($lines, $i);
                        $key = $label . '@' . $page;
                        if ($tablePages !== null && ! isset($consumedTables[$key])) {
                            foreach ($this->tables->regions($tablePages[$page] ?? '') as $region) {
                                if ($region['label'] === $label) {
                                    $parsed = $this->tables->parse($this->withoutRunningHeaders($region['lines']));
                                    $blocks[] = ['type' => 'table', 'page' => $page, 'label' => $label] + $parsed;
                                    $consumedTables[$key] = true;
                                    $this->stats['tables']++;
                                    break;
                                }
                            }
                        }
                        continue;
                    }

                    // figure
                    $flush();
                    [$figure, $i] = $this->readFigure($lines, $i, $label, $title, $page);
                    $blocks[] = $figure;
                    $this->stats['figures']++;
                    continue;
                }

                // ---- TOC headings ----
                $match = $this->matchHeading($lines, $i, $matchable, $pointer, $page);
                if ($match !== null) {
                    $flush();
                    $entry = $matchable[$match['index']];
                    $this->stats['headings_skipped'] += $match['index'] - $pointer;
                    $this->stats['headings_matched']++;
                    $pointer = $match['index'] + 1;
                    $i += $match['lines'] - 1;

                    $openBox = null;
                    $sectionLevel = $entry['level'];
                    $blocks[] = ['type' => 'section', 'level' => $entry['level'], 'kind' => $entry['kind'], 'label' => null,
                        'title' => $entry['title'], 'page' => $page];
                    continue;
                }

                // ---- Subheadings inside boxes (not in the TOC) ----
                if ($openBox !== null && $this->looksLikeHeading($line, $lines[$i + 1] ?? '')) {
                    $flush();
                    $blocks[] = ['type' => 'section', 'level' => $openBox['level'] + 1, 'kind' => 'section', 'label' => null,
                        'title' => $line, 'page' => $page];
                    continue;
                }

                // ---- Chart residue and letter-spaced page furniture
                //      ("132 C H A P T E R 4" on rotated table pages) ----
                if ($this->isNumericJunk($line) || preg_match('/^(\d+\s+)?(\p{Lu}\s){3,}\p{Lu}(\s+\d+)?$/u', $line)) {
                    $this->stats['junk_lines']++;
                    continue;
                }

                $text[] = $line;
            }

            $flush();
        }

        return $blocks;
    }

    // ------------------------------------------------------------------
    // Page numbering and dividers
    // ------------------------------------------------------------------

    /**
     * The constant offset between PDF page numbers and printed page
     * numbers, found from bare-number lines at page edges. Null when no
     * offset is consistent across enough pages.
     *
     * @param  array<int, string>  $rawPages
     * @return int|null
     */
    protected function pageOffset(array $rawPages): ?int
    {
        $votes = [];
        foreach ($rawPages as $page => $text) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), 'strlen'));
            $edge = array_merge(array_slice($lines, 0, 4), array_slice($lines, -4));
            foreach (array_unique($edge) as $line) {
                if (preg_match('/^\d{1,4}$/', $line) && (int) $line < $page + 50) {
                    $offset = $page - (int) $line;
                    $votes[$offset] = ($votes[$offset] ?? 0) + 1;
                }
            }
        }

        if (empty($votes)) {
            return null;
        }

        arsort($votes);
        $best = (int) array_key_first($votes);

        return $votes[$best] >= max(5, 0.2 * count($rawPages)) ? $best : null;
    }

    /**
     * Pages consisting only of a short all-caps title: chapter and part
     * dividers.
     *
     * @param  array<int, string>  $rawPages
     * @param  array  $toc
     * @return array<int, array{kind: string, label: string|null, title: string}>
     */
    protected function dividers(array $rawPages, array $toc): array
    {
        $tocTitles = [];
        foreach ($toc as $entry) {
            $tocTitles[$this->norm($entry['title'])] = $entry['title'];
        }
        $lastTocPage = empty($toc) ? 0 : max(array_column($toc, 'toc_page'));

        $dividers = [];
        foreach ($rawPages as $page => $text) {
            if ($page <= $lastTocPage) {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), 'strlen'));
            $joined = implode(' ', $lines);
            if (empty($lines) || count($lines) > 6 || mb_strlen($joined) > 250) {
                continue;
            }
            // "CHAPTER 3" pages are dividers whatever the case of their
            // subtitle; other dividers must be all caps ("STATISTICAL APPENDIX").
            $isChapter = (bool) preg_match('/^(CHAPTER|PART)\s+\d+$/u', $lines[0]);
            $letters = preg_replace('/[^\pL]/u', '', $joined);
            if (! $isChapter && ($letters === '' || mb_strlen(preg_replace('/[^\p{Lu}]/u', '', $letters)) < 0.85 * mb_strlen($letters))) {
                continue;
            }

            $label = null;
            $kind = 'part';
            if (preg_match('/^(CHAPTER|PART)\s+(\d+)$/u', $lines[0], $m)) {
                $label = ucfirst(strtolower($m[1])) . ' ' . $m[2];
                $kind = 'chapter';
                array_shift($lines);
            }

            $titleCaps = trim(implode(' ', $lines));
            if ($titleCaps === '') {
                continue;
            }
            $title = $tocTitles[$this->norm($titleCaps)] ?? $this->titleCase($titleCaps);

            $dividers[$page] = ['kind' => $kind, 'label' => $label, 'title' => $title];
        }

        return $dividers;
    }

    // ------------------------------------------------------------------
    // TOC levels and heading matching
    // ------------------------------------------------------------------

    /**
     * Turn TOC indentation into levels. Indentation columns shift between
     * TOC pages (odd/even margins), so levels are decided per page,
     * using entries whose level is known as anchors:
     *   - front matter (roman page numbers) and part dividers: level 1;
     *   - chapter titles, which this kind of TOC prints at the same
     *     indent as the chapter's own top-level sections: that column is
     *     level 2 for the sections.
     * Remaining columns nest one level deeper each, left to right.
     *
     * @param  array  $toc
     * @param  array  $dividers
     * @return array<int, array>
     */
    protected function assignLevels(array $toc, array $dividers): array
    {
        $chapterTitles = [];
        $partTitles = [];
        foreach ($dividers as $divider) {
            if ($divider['kind'] === 'chapter') {
                $chapterTitles[$this->norm($divider['title'])] = true;
            } else {
                $partTitles[$this->norm($divider['title'])] = true;
            }
        }

        $byPage = [];
        foreach ($toc as $i => $entry) {
            $norm = $this->norm($entry['title']);
            $toc[$i]['anchor_chapter'] = isset($chapterTitles[$norm]);
            $toc[$i]['anchor_level1'] = isset($partTitles[$norm]) || preg_match('/^[ivxlcdm]+$/', $entry['page_label']);
            $toc[$i]['anchor_divider'] = isset($chapterTitles[$norm]) || isset($partTitles[$norm]);
            $byPage[$entry['toc_page']][] = $i;
        }

        foreach ($byPage as $indexes) {
            $columns = [];
            foreach ($indexes as $i) {
                $columns[] = $toc[$i]['indent'];
            }
            $clusters = $this->clusterColumns($columns);

            $level1Clusters = [];
            $level2Cluster = null;
            foreach ($indexes as $i) {
                $c = $this->clusterOf($toc[$i]['indent'], $clusters);
                if ($toc[$i]['anchor_level1']) {
                    $level1Clusters[$c] = true;
                }
                if ($toc[$i]['anchor_chapter'] && $level2Cluster === null) {
                    $level2Cluster = $c;
                }
            }

            foreach ($indexes as $i) {
                $c = $this->clusterOf($toc[$i]['indent'], $clusters);
                if ($toc[$i]['anchor_chapter']) {
                    $level = 1;
                } elseif (isset($level1Clusters[$c]) && ($level2Cluster === null || $c < $level2Cluster)) {
                    $level = 1;
                } elseif ($level2Cluster !== null) {
                    $level = 2 + max(0, $c - $level2Cluster);
                } else {
                    // No chapter anchor on this page: the first column right
                    // of any level-1 column is level 2.
                    $base = empty($level1Clusters) ? 0 : max(array_keys($level1Clusters)) + 1;
                    $level = 2 + max(0, $c - $base);
                }

                $toc[$i]['level'] = $level;
                $toc[$i]['kind'] = $level === 1 ? 'front' : 'section';
            }
        }

        return $toc;
    }

    /**
     * Distinct indentation columns (within 2 characters) in ascending order.
     *
     * @param  array<int, int>  $columns
     * @return array<int, int>  cluster index => representative column
     */
    protected function clusterColumns(array $columns): array
    {
        sort($columns);
        $clusters = [];
        foreach ($columns as $column) {
            if (empty($clusters) || $column - end($clusters) > 2) {
                $clusters[] = $column;
            }
        }

        return $clusters;
    }

    /**
     * @param  int  $column
     * @param  array<int, int>  $clusters
     * @return int
     */
    protected function clusterOf(int $column, array $clusters): int
    {
        $best = 0;
        foreach ($clusters as $index => $start) {
            if ($column >= $start - 2) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * Does the line at $i (alone, or joined with the next one or two, for
     * wrapped headings) equal one of the next few expected TOC entries,
     * on or next to the page the TOC promises?
     *
     * @param  array<int, string>  $lines
     * @param  int  $i
     * @param  array  $entries
     * @param  int  $pointer
     * @param  int  $page
     * @return array{index: int, lines: int}|null
     */
    protected function matchHeading(array $lines, int $i, array $entries, int $pointer, int $page): ?array
    {
        $line = $lines[$i];
        if (mb_strlen($line) > 200 || preg_match('/[.;,]$/u', $line)) {
            return null;
        }

        $candidates = [1 => $this->norm($line)];
        if (isset($lines[$i + 1])) {
            $candidates[2] = $this->norm($line . ' ' . $lines[$i + 1]);
        }
        if (isset($lines[$i + 2])) {
            $candidates[3] = $this->norm($line . ' ' . $lines[$i + 1] . ' ' . $lines[$i + 2]);
        }

        $last = min(count($entries) - 1, $pointer + 6);
        for ($index = $pointer; $index <= $last; $index++) {
            $entry = $entries[$index];
            if (! $this->onExpectedPage($entry, $page)) {
                continue;
            }
            foreach ($candidates as $lineCount => $candidate) {
                if ($candidate === $this->norm($entry['title'])) {
                    return ['index' => $index, 'lines' => $lineCount];
                }
            }
        }

        return null;
    }

    /**
     * @param  array  $entry
     * @param  int  $page
     * @return bool
     */
    protected function onExpectedPage(array $entry, int $page): bool
    {
        if (! ctype_digit($entry['page_label'])) {
            return true;
        }

        $expected = array_search($entry['page_label'], $this->pageLabels, true);

        return $expected === false || abs($expected - $page) <= 1;
    }

    // ------------------------------------------------------------------
    // Boxes, figures, tables
    // ------------------------------------------------------------------

    /**
     * @param  array<int, string>  $lines
     * @param  string  $label
     * @return bool
     */
    protected function pageContinuesBox(array $lines, string $label): bool
    {
        $pattern = '/^' . preg_quote(strtoupper($label), '/') . '\b.*\(continued\)/u';
        foreach (array_slice($lines, 0, 8) as $line) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Skip a table's (scrambled, reading-order) lines: up to and through
     * its "Source:" / "Note:" block.
     *
     * @param  array<int, string>  $lines
     * @param  int  $i  caption index
     * @return int  index of the region's last line
     */
    protected function skipCaptionedRegion(array $lines, int $i): int
    {
        $count = count($lines);
        $inNotes = false;
        $previous = '';

        for ($j = $i + 1; $j < $count; $j++) {
            $line = $lines[$j];
            if (preg_match('/^(Sources?|Notes?)\s*:/u', $line)) {
                $inNotes = true;
            } elseif ($inNotes) {
                $isNote = preg_match('/^(\d{1,2}|[a-zA-Z])\.\s/u', $line)
                    || preg_match('/^\p{Ll}/u', $line)
                    || ! preg_match('/[.:;]$/u', $previous);
                if (! $isNote || preg_match(self::CAPTION, $line)) {
                    return $j - 1;
                }
            } elseif (preg_match(self::CAPTION, $line)) {
                return $j - 1;
            }
            $previous = $line;
        }

        return $count - 1;
    }

    /**
     * Figure: caption + key-finding sentence + panel titles + sources and
     * notes. Everything else in the region is chart residue (axis ticks,
     * legend fragments) and is dropped.
     *
     * @param  array<int, string>  $lines
     * @param  int  $i
     * @param  string  $label
     * @param  string  $title
     * @param  int  $page
     * @return array{0: array, 1: int}
     */
    protected function readFigure(array $lines, int $i, string $label, string $title, int $page): array
    {
        $parts = [];
        $count = count($lines);
        $j = $i + 1;

        // Key-finding summary sentence(s) right under the caption.
        while ($j < $count && mb_strlen($lines[$j]) >= 60 && ! preg_match('/^[A-H]\.\s/u', $lines[$j])
            && ! preg_match(self::CAPTION, $lines[$j]) && ! preg_match('/^(Sources?|Notes?)\s*:/u', $lines[$j])) {
            $parts[] = $lines[$j];
            $j++;
            if (count($parts) >= 2) {
                break;
            }
        }

        $panels = [];
        $notes = [];
        $inNotes = false;
        $previous = '';
        for (; $j < $count; $j++) {
            $line = $lines[$j];
            if (preg_match(self::CAPTION, $line)) {
                break;
            }
            if (preg_match('/^(Sources?|Notes?)\s*:/u', $line)) {
                $inNotes = true;
                $notes[] = $line;
            } elseif ($inNotes) {
                $isNote = preg_match('/^(\d{1,2}|[a-zA-Z])\.\s/u', $line)
                    || preg_match('/^\p{Ll}/u', $line)
                    || ! preg_match('/[.:;]$/u', $previous);
                if (! $isNote) {
                    break;
                }
                $notes[] = $line;
            } elseif (preg_match('/^[A-H]\.\s+\S/u', $line) && mb_strlen($line) <= 120) {
                $panels[] = $line;
            } elseif (mb_strlen($line) >= 150 && preg_match('/[.!?]$/u', $line)) {
                // Body prose resumed on a figure without a sources block.
                break;
            }
            $previous = $line;
        }

        $text = trim(implode(' ', $parts));
        if (! empty($panels)) {
            $text .= "\nPanels: " . implode('; ', $panels);
        }
        if (! empty($notes)) {
            $text .= "\n" . mb_substr(implode(' ', $notes), 0, 900);
        }

        return [['type' => 'figure', 'page' => $page, 'label' => $label, 'title' => $title, 'text' => trim($text)], $j - 1];
    }

    /**
     * A page that is one big uncaptioned table (e.g. a statistical
     * appendix): most of its "-table" mode lines end in a run of numbers,
     * and it has no figure/table captions of its own.
     *
     * @param  array<int, string>  $lines
     * @param  string  $tablePage
     * @return bool
     */
    protected function isWholePageTable(array $lines, string $tablePage): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/^(FIGURE|TABLE|BOX)\s+[A-Z]{0,2}\d/u', $line)) {
                return false;
            }
        }

        $nonEmpty = 0;
        $numericRows = 0;
        $hasYearHeader = false;
        foreach (explode("\n", $tablePage) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $nonEmpty++;
            if (preg_match('/\pL.*?(\s+(-?[\d,]*\.?\d+|\.\.)){3,}\s*$/u', $line)) {
                $numericRows++;
            }
            if (preg_match_all('/(?<!\S)(\d{4}[ef]?|\d{2}Q\d[ef]?)(?!\S)/u', $line) >= 4) {
                $hasYearHeader = true;
            }
        }

        // Long footnotes can outnumber the rows on a table's last page; a
        // row of year/quarter column headers confirms it's a table.
        return $numericRows >= 8
            && ($numericRows >= 0.3 * $nonEmpty || $hasYearHeader);
    }

    /**
     * @param  string  $tablePage
     * @param  int  $page
     * @return array
     */
    protected function wholePageTable(string $tablePage, int $page): array
    {
        $lines = $this->withoutRunningHeaders(explode("\n", $tablePage));
        while (! empty($lines) && trim($lines[0]) === '') {
            array_shift($lines);
        }

        $parsed = $this->tables->parse($lines);

        return ['type' => 'table', 'page' => $page, 'label' => null] + $parsed;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    protected function withoutRunningHeaders(array $lines): array
    {
        return array_values(array_filter($lines, function ($line) {
            return ! $this->cleaner->containsRunningHeader($line);
        }));
    }

    // ------------------------------------------------------------------
    // Line classification helpers
    // ------------------------------------------------------------------

    /**
     * A short, capitalised line with no closing punctuation, followed by
     * a paragraph - a subheading not listed in the TOC.
     *
     * @param  string  $line
     * @param  string  $next
     * @return bool
     */
    protected function looksLikeHeading(string $line, string $next): bool
    {
        return mb_strlen($line) >= 4 && mb_strlen($line) <= 80
            && preg_match('/^\p{Lu}/u', $line)
            && ! preg_match('/[.,;:)]$/u', $line)
            && preg_match('/\pL{3,}/u', $line)
            && ! preg_match('/\d{2,}/u', $line)
            && mb_strlen($next) >= 120;
    }

    /**
     * Axis ticks and legend fragments: short lines that are mostly numbers.
     *
     * @param  string  $line
     * @return bool
     */
    protected function isNumericJunk(string $line): bool
    {
        if (mb_strlen($line) > 80) {
            return false;
        }
        $tokens = preg_split('/\s+/u', $line);
        $numeric = count(array_filter($tokens, function ($t) {
            return preg_match('/^[-+]?[\d.,]+[%ef]?$/u', $t);
        }));

        return $numeric >= 1 && $numeric >= 0.6 * count($tokens);
    }

    /**
     * Comparison key for titles: lowercase letters/digits only.
     *
     * @param  string  $text
     * @return string
     */
    protected function norm(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }

    /**
     * @param  string  $caps
     * @return string
     */
    protected function titleCase(string $caps): string
    {
        $small = ['and', 'or', 'of', 'the', 'in', 'on', 'for', 'to', 'a', 'an', 'at', 'by'];
        $words = explode(' ', mb_strtolower($caps));
        foreach ($words as $i => $word) {
            if ($i === 0 || ! in_array($word, $small, true)) {
                $words[$i] = mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
            }
        }

        return implode(' ', $words);
    }
}
