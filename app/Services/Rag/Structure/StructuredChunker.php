<?php

namespace App\Services\Rag\Structure;

use App\Services\Rag\Ingestion\TextChunker;

/**
 * Turns StructureParser's block stream into a section tree and
 * section-aware chunks:
 *
 *  - A chunk never spans two sections: text is chunked per section, so a
 *    chunk about South Asia's outlook can't drag in the start of
 *    Sub-Saharan Africa's.
 *  - Every chunk records its section, and the section's breadcrumb
 *    ("Chapter 2: Regional Outlooks > South Asia > Outlook") travels with
 *    it into embedding, keyword search and the LLM prompt - most passages
 *    never name the region/topic they're about ("Growth in the region is
 *    projected to...").
 *  - Tables become their own leaf sections, split into row groups that
 *    each repeat the caption, so every chunk is self-describing; their
 *    notes (fiscal-year definitions, abbreviations) go in the last chunk.
 *  - Figures become one chunk each: caption, key finding, panels, notes.
 */
class StructuredChunker
{
    /**
     * @var int
     */
    protected $chunkSize;

    /**
     * @var int
     */
    protected $overlap;

    /**
     * @var int
     */
    protected $tableChunkSize;

    public function __construct(int $chunkSize = 1000, int $overlap = 150, int $tableChunkSize = 1500)
    {
        $this->chunkSize = $chunkSize;
        $this->overlap = $overlap;
        $this->tableChunkSize = $tableChunkSize;
    }

    /**
     * @param  array<int, array>  $blocks
     * @param  string  $documentTitle  used for text that precedes the first heading
     * @return array{sections: array<int, array>, chunks: array<int, array>}
     */
    public function chunk(array $blocks, string $documentTitle): array
    {
        $sections = [];
        $chunks = [];
        $stack = [];
        $buffer = [];   // page => text, for the current section

        $current = function () use (&$stack) {
            return empty($stack) ? null : end($stack);
        };

        $flush = function () use (&$buffer, &$chunks, &$sections, $current) {
            if (empty($buffer)) {
                return;
            }
            $section = $current();
            foreach ((new TextChunker($this->chunkSize, $this->overlap))->chunk($buffer) as $piece) {
                $chunks[] = $piece + ['kind' => $section !== null && $sections[$section]['kind'] === 'box' ? 'box' : 'text', 'section' => $section];
            }
            $buffer = [];
        };

        $open = function (array $block) use (&$stack, &$sections) {
            while (! empty($stack) && $sections[end($stack)]['level'] >= $block['level']) {
                array_pop($stack);
            }
            $parent = empty($stack) ? null : end($stack);
            $display = $this->display($block);
            $ordinal = count($sections);
            $sections[$ordinal] = [
                'ordinal' => $ordinal,
                'parent' => $parent,
                'level' => $block['level'],
                'kind' => $block['kind'],
                'label' => $block['label'] ?? null,
                'title' => $block['title'],
                'path' => $parent === null ? $display : $sections[$parent]['path'] . ' > ' . $display,
                'page_start' => $block['page'],
                'page_end' => $block['page'],
            ];
            $stack[] = $ordinal;

            return $ordinal;
        };

        foreach ($blocks as $block) {
            switch ($block['type']) {
                case 'section':
                    $flush();
                    $open($block);
                    break;

                case 'close':
                    $flush();
                    while (! empty($stack) && $sections[end($stack)]['level'] >= $block['level']) {
                        array_pop($stack);
                    }
                    break;

                case 'text':
                    if (trim($block['text']) === '') {
                        break;
                    }
                    if ($current() === null) {
                        $open(['level' => 1, 'kind' => 'front', 'label' => null, 'title' => $documentTitle, 'page' => $block['page']]);
                    }
                    $buffer[$block['page']] = isset($buffer[$block['page']])
                        ? $buffer[$block['page']] . "\n\n" . $block['text']
                        : $block['text'];
                    $sections[$current()]['page_end'] = max($sections[$current()]['page_end'], $block['page']);
                    break;

                case 'table':
                case 'figure':
                    // No flush: figures/tables float mid-paragraph in the
                    // layout, and the section's text continues around them.
                    $parentLevel = $current() === null ? 0 : $sections[$current()]['level'];
                    $leaf = $open([
                        'level' => $parentLevel + 1,
                        'kind' => $block['type'],
                        'label' => $block['label'],
                        'title' => $block['type'] === 'table' ? $this->tableTitle($block) : $block['title'],
                        'page' => $block['page'],
                    ]);
                    foreach ($block['type'] === 'table' ? $this->tableChunks($block) : $this->figureChunks($block) as $content) {
                        $chunks[] = ['content' => $content, 'page_start' => $block['page'], 'page_end' => $block['page'],
                            'kind' => $block['type'], 'section' => $leaf];
                    }
                    array_pop($stack);
                    break;
            }
        }
        $flush();

        // Propagate page ranges up the tree.
        for ($i = count($sections) - 1; $i >= 0; $i--) {
            $parent = $sections[$i]['parent'];
            if ($parent !== null) {
                $sections[$parent]['page_end'] = max($sections[$parent]['page_end'], $sections[$i]['page_end']);
            }
        }

        return ['sections' => $sections, 'chunks' => $chunks];
    }

    /**
     * "Chapter 2: Regional Outlooks", "Box 3.1: The costs of hidden debt",
     * "Table 2.10: South Asia economy forecasts", "Outlook".
     *
     * @param  array  $block
     * @return string
     */
    protected function display(array $block): string
    {
        return empty($block['label']) ? $block['title'] : $block['label'] . ': ' . $block['title'];
    }

    /**
     * @param  array  $block
     * @return string
     */
    protected function tableTitle(array $block): string
    {
        $caption = preg_replace('/^TABLE\s+[A-Z]{0,2}\d+(\.\d+)*\.?\s*/u', '', $block['caption']);
        $caption = trim(preg_replace('/(\s+\d{1,2})+$/u', '', $caption));

        return $caption !== '' ? $caption : 'Table';
    }

    /**
     * Row groups, each prefixed with the caption and subtitle; notes in
     * the last group (or their own chunk if they don't fit).
     *
     * @param  array  $block
     * @return array<int, string>
     */
    protected function tableChunks(array $block): array
    {
        $head = trim(($block['label'] ? $block['label'] . ': ' : '') . $this->tableTitle($block)
            . ($block['subtitle'] !== '' ? "\n" . $block['subtitle'] : ''));
        $notes = mb_substr($block['notes'], 0, 900);

        $chunks = [];
        $rows = [];
        $length = mb_strlen($head);
        foreach ($block['rows'] as $row) {
            if (! empty($rows) && $length + mb_strlen($row) + 1 > $this->tableChunkSize) {
                $chunks[] = $head . "\n" . implode("\n", $rows);
                $rows = [];
                $length = mb_strlen($head);
            }
            $rows[] = $row;
            $length += mb_strlen($row) + 1;
        }

        if (! empty($rows)) {
            $last = $head . "\n" . implode("\n", $rows);
            if ($notes !== '' && mb_strlen($last) + mb_strlen($notes) + 1 <= $this->tableChunkSize * 1.3) {
                $last .= "\n" . $notes;
                $notes = '';
            }
            $chunks[] = $last;
        }
        if ($notes !== '') {
            $chunks[] = $head . "\n" . $notes;
        }

        return $chunks;
    }

    /**
     * @param  array  $block
     * @return array<int, string>
     */
    protected function figureChunks(array $block): array
    {
        $content = $block['label'] . ': ' . $block['title'] . ($block['text'] !== '' ? "\n" . $block['text'] : '');

        if (mb_strlen($content) <= $this->tableChunkSize) {
            return [$content];
        }

        return array_column((new TextChunker($this->tableChunkSize, 0))->chunk([0 => $content]), 'content');
    }
}
