<?php

namespace App\Services\Rag\Structure;

/**
 * Turns a table region from pdftotext's "-table" mode into self-contained
 * row records.
 *
 * Every data row is written out against the header row in force above
 * it, e.g.
 *
 *   India | 2023/24: 7.2 | 2024/25: 7.1 | 2025/26e: 7.7 | 2026/27f: 6.6 | ...
 *
 * so a chunk holding a slice of a long table never loses its column
 * meaning - and tables whose header changes part-way (calendar-year vs
 * fiscal-year rows) stay correct. Column groups printed above the year
 * row ("Percentage-point differences from January 2026 projections")
 * are attached to the columns they sit over.
 */
class TableParser
{
    /**
     * A header cell: a year, fiscal year, or quarter, optionally marked as
     * estimate/forecast: 2023, 2025e, 2026f, 2023/24, 2026/27f, 24Q4, 2027-28f.
     */
    const HEADER_CELL = '/^(\d{4}([\/\x{2013}-]\d{2,4})?|\d{2}Q\d)[ef]?$/u';

    /**
     * A value cell: a number (signed, decimal, thousands-separated,
     * percent) or a ".." / "-" placeholder.
     */
    const VALUE_CELL = '/^(-?\(?[\d,]*\.?\d+\)?%?|\.\.|\x{2013}|-|n\.a\.)$/u';

    /**
     * Find every captioned table on a "-table" mode page: from its
     * "TABLE 2.10 ..." caption line to the end of its source/notes block
     * (or the next caption).
     *
     * @param  string  $pageText
     * @return array<int, array{label: string, lines: array<int, string>}>
     */
    public function regions(string $pageText): array
    {
        $regions = [];
        $current = null;
        $inNotes = false;
        $previousNote = '';

        foreach (explode("\n", $pageText) as $line) {
            $trimmed = trim($line);

            if (preg_match('/^TABLE\s+([A-Z]{0,2}\d+(?:\.\d+)*)\b/u', $trimmed, $m)) {
                if ($current !== null) {
                    $regions[] = $current;
                }
                $current = ['label' => 'Table ' . $m[1], 'lines' => [$line]];
                $inNotes = false;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($trimmed === '') {
                $current['lines'][] = $line;
                continue;
            }

            if (preg_match('/^(Sources?|Notes?)\s*:/u', $trimmed)) {
                $inNotes = true;
            } elseif ($inNotes) {
                $isNoteLine = preg_match('/^(\d{1,2}|[a-zA-Z])\.\s/u', $trimmed)
                    || preg_match('/^\p{Ll}/u', $trimmed)
                    || ! preg_match('/[.:;]$/u', $previousNote);
                if (! $isNoteLine) {
                    $regions[] = $current;
                    $current = null;
                    $inNotes = false;
                    continue;
                }
            }

            $current['lines'][] = $line;
            if ($inNotes) {
                $previousNote = $trimmed;
            }
        }

        if ($current !== null) {
            $regions[] = $current;
        }

        return $regions;
    }

    /**
     * @param  array<int, string>  $lines  the table region (caption line first), layout preserved
     * @return array{caption: string, subtitle: string, rows: array<int, string>, notes: string}
     */
    public function parse(array $lines): array
    {
        $captionLine = array_shift($lines);
        // The caption may continue in further cells of its own line
        // ("...developing      economies1"); a capitalised cell there is a
        // column-group label instead ("Percentage-point differences").
        $captionCells = $this->cells($captionLine);
        $caption = $captionCells[0]['text'] ?? '';
        $consumed = 1;
        foreach (array_slice($captionCells, 1) as $cell) {
            if (! preg_match('/^\p{Ll}/u', $cell['text'])) {
                break;
            }
            $caption .= ' ' . $cell['text'];
            $consumed++;
        }
        if ($consumed > 1 && isset($captionCells[$consumed - 1])) {
            $last = $captionCells[$consumed - 1];
            $preHeaderCaption = str_repeat(' ', $last['offset'] + mb_strlen($last['text']))
                . mb_substr($captionLine, $last['offset'] + mb_strlen($last['text']));
        }

        // A caption wrapped onto the next line ("TABLE 1.2 Emerging market
        // and developing" + "economies"): a short lowercase-led first cell.
        foreach ($lines as $index => $next) {
            if (trim($next) === '') {
                continue;
            }
            $first = $this->cells($next)[0] ?? null;
            if ($first !== null && $first['offset'] < 20 && preg_match('/^\p{Ll}[\p{L} ,\-]{0,50}\d?$/u', $first['text'])) {
                $caption .= ' ' . $first['text'];
                $lines[$index] = str_repeat(' ', $first['offset'] + mb_strlen($first['text']))
                    . mb_substr($next, $first['offset'] + mb_strlen($first['text']));
            }
            break;
        }
        // Footnote markers glued to the last word: "forecasts1" => "forecasts".
        $caption = preg_replace('/(\pL)\d{1,2}$/u', '$1', $caption);
        $subtitle = [];
        $rows = [];
        $notes = [];
        $header = null;
        // The caption line can also carry column-group text on its right.
        $preHeader = [$preHeaderCaption ?? $captionLine];
        $inNotes = false;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $trimmed = trim($line);

            if ($inNotes || preg_match('/^(Sources?|Notes?)\s*:/u', $trimmed)) {
                $inNotes = true;
                $notes[] = preg_replace('/\s+/u', ' ', $trimmed);
                continue;
            }

            $cells = $this->cells($line);

            if ($this->isHeaderRow($cells)) {
                $header = $this->buildHeader($cells, $preHeader, $header);
                $preHeader = [];
                // A header row may carry a row-group label at its left
                // ("Fiscal-year basis 1   2023/24   2024/25 ...").
                $first = $cells[0]['text'];
                if (! preg_match(self::HEADER_CELL, $first)) {
                    $rows[] = $this->cleanLabel($first) . ':';
                }
                continue;
            }

            $row = $header !== null ? $this->serializeRow($line, $header) : null;

            if ($row !== null) {
                $rows[] = $row;
            } elseif ($header === null) {
                // Before the first header: subtitle text on the left and
                // column-group labels on the right.
                $preHeader[] = $line;
                $leftText = $cells[0]['offset'] < 20 ? $cells[0]['text'] : '';
                if ($leftText !== '' && empty($rows)) {
                    $subtitle[] = $leftText;
                }
            } else {
                // Group labels inside the table ("Memo items: GDP",
                // "Calendar-year basis") or wrapped label text.
                $rows[] = preg_replace('/\s{2,}/u', ' ', $trimmed);
            }
        }

        return [
            'caption' => $caption,
            'subtitle' => implode(' ', $subtitle),
            'rows' => $rows,
            'notes' => implode(' ', $notes),
        ];
    }

    /**
     * Split a layout line into cells on runs of 2+ spaces, keeping each
     * cell's starting column.
     *
     * @param  string  $line
     * @return array<int, array{text: string, offset: int}>
     */
    public function cells(string $line): array
    {
        $cells = [];
        if (preg_match_all('/\S(?:.*?\S)?(?=\s{2,}|\s*$)/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $match) {
                $cells[] = ['text' => $match[0], 'offset' => mb_strlen(substr($line, 0, $match[1]))];
            }
        }

        return $cells;
    }

    /**
     * @param  array  $cells
     * @return bool
     */
    protected function isHeaderRow(array $cells): bool
    {
        $tokens = [];
        foreach ($cells as $cell) {
            foreach (preg_split('/\s+/u', $cell['text']) as $token) {
                $tokens[] = $token;
            }
        }

        $headerTokens = count(array_filter($tokens, function ($t) {
            return preg_match(self::HEADER_CELL, $t);
        }));

        return $headerTokens >= 2 && $headerTokens >= 0.6 * (count($tokens) - 3);
    }

    /**
     * Column names with their offsets, plus the group label (from the
     * lines above) for columns that sit under one. A group label covers the
     * columns whose start falls within its text span - labels are usually
     * centred over their columns, so they may start a little left of them.
     * A header row repeated mid-table (e.g. switching to fiscal years)
     * inherits the previous header's groups column by column.
     *
     * @param  array  $cells
     * @param  array<int, string>  $preHeader
     * @param  array|null  $previous
     * @return array<int, array{name: string, offset: int, group?: string}>
     */
    protected function buildHeader(array $cells, array $preHeader, ?array $previous): array
    {
        $columns = [];
        foreach ($cells as $cell) {
            // Cells can hold several single-space-separated years.
            $offset = $cell['offset'];
            foreach (preg_split('/(\s+)/u', $cell['text'], -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
                if (preg_match(self::HEADER_CELL, $piece)) {
                    $columns[] = ['name' => $piece, 'offset' => $offset];
                }
                $offset += mb_strlen($piece);
            }
        }

        if (empty($columns)) {
            return [];
        }

        $firstColumn = $columns[0]['offset'];
        $groups = [];
        foreach ($preHeader as $text) {
            foreach ($this->cells($text) as $cell) {
                if ($cell['offset'] + 8 < $firstColumn || preg_match('/^\(continued\)$/iu', $cell['text'])) {
                    continue;
                }
                $end = $cell['offset'] + mb_strlen($cell['text']);
                $key = null;
                foreach ($groups as $i => $group) {
                    if ($cell['offset'] <= $group['end'] + 2 && $end >= $group['start'] - 2) {
                        $key = $i;
                        break;
                    }
                }
                if ($key === null) {
                    $groups[] = ['start' => $cell['offset'], 'end' => $end, 'text' => $cell['text']];
                } else {
                    $groups[$key]['start'] = min($groups[$key]['start'], $cell['offset']);
                    $groups[$key]['end'] = max($groups[$key]['end'], $end);
                    $groups[$key]['text'] .= ' ' . $cell['text'];
                }
            }
        }

        // Group labels are centred over their columns, so a label's text
        // span understates the columns it covers. Adjacent groups split the
        // columns at the midpoint between them; the first group reaches back
        // to the first column if it starts over the first couple of columns
        // (a table-wide grouping), otherwise it starts at its own text (a
        // group over just the right-hand columns, e.g. "Percentage-point
        // differences"); the last group extends to the last column.
        usort($groups, function ($a, $b) {
            return $a['start'] <=> $b['start'];
        });
        $ranges = [];
        foreach ($groups as $g => $group) {
            $left = $g === 0
                ? ($group['start'] <= ($columns[1]['offset'] ?? $columns[0]['offset']) ? PHP_INT_MIN : $group['start'] - 3)
                : ($groups[$g - 1]['end'] + $group['start']) / 2;
            $right = isset($groups[$g + 1]) ? ($group['end'] + $groups[$g + 1]['start']) / 2 : PHP_INT_MAX;
            $ranges[] = [$left, $right, trim(preg_replace('/\s+/u', ' ', $group['text']))];
        }

        foreach ($columns as $i => $column) {
            foreach ($ranges as $range) {
                if ($column['offset'] >= $range[0] && $column['offset'] < $range[1]) {
                    $columns[$i]['group'] = $range[2];
                }
            }
            if (empty($groups) && $previous !== null && count($previous) === count($columns) && isset($previous[$i]['group'])) {
                $columns[$i]['group'] = $previous[$i]['group'];
            }
        }

        return $columns;
    }

    /**
     * "India   7.2   7.1 ..." => "India | 2023/24: 7.2 | 2024/25: 7.1 | ...",
     * taking the last N value-like tokens as the N columns (so footnote
     * markers glued to the label - "Myanmar 2 3" - stay in the label).
     *
     * @param  string  $line
     * @param  array  $header
     * @return string|null  null if the line isn't a data row for this header
     */
    protected function serializeRow(string $line, array $header): ?string
    {
        $tokens = preg_split('/\s+/u', trim($line));
        $count = count($header);

        if (count($tokens) <= $count) {
            return null;
        }

        $values = array_slice($tokens, -$count);
        foreach ($values as $value) {
            if (! preg_match(self::VALUE_CELL, $value)) {
                return null;
            }
        }

        $label = $this->cleanLabel(implode(' ', array_slice($tokens, 0, -$count)));
        if ($label === '' || ! preg_match('/\pL/u', $label)) {
            return null;
        }

        $parts = [];
        $currentGroup = null;
        foreach ($header as $i => $column) {
            $group = $column['group'] ?? null;
            $prefix = '';
            if ($group !== null && $group !== $currentGroup) {
                $prefix = "[{$group}] ";
            }
            $currentGroup = $group;
            $parts[] = $prefix . $column['name'] . ': ' . $values[$i];
        }

        return $label . ' | ' . implode(' | ', $parts);
    }

    /**
     * Drop trailing footnote markers from a row label: "Egypt, Arab Rep. 2" => "Egypt, Arab Rep.".
     *
     * @param  string  $label
     * @return string
     */
    protected function cleanLabel(string $label): string
    {
        return trim(preg_replace('/(\s+\d{1,2})+$/u', '', trim($label)));
    }
}
