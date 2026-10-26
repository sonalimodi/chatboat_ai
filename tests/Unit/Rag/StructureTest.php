<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\Structure\GlyphRepair;
use App\Services\Rag\Structure\StructuredChunker;
use App\Services\Rag\Structure\TableParser;
use App\Services\Rag\Structure\TocParser;
use Tests\TestCase;

class StructureTest extends TestCase
{
    public function test_glyph_repair_restores_lost_th_ligatures_only_at_sentence_starts()
    {
        $repair = new GlyphRepair();

        $this->assertSame(
            'The conflict raised prices. These findings matter. Growth in Thailand slowed.',
            $repair->repair('e conflict raised prices. ese findings matter. Growth in ailand slowed.')
        );

        // A wrapped continuation ("...which" / "is expected") is left alone.
        $this->assertSame(
            "Growth in the region, which\nis expected to slow, fell.",
            $repair->repair("Growth in the region, which\nis expected to slow, fell.")
        );

        $this->assertSame('Well-functioning financial markets', $repair->repair('Well-functioning nancial markets'));
    }

    public function test_toc_parser_reads_entries_indentation_and_skips_element_lists()
    {
        $page = implode("\n", [
            'Contents',
            'Foreword ........................................ xv',
            'Chapter 1  Summary ................................ 3',
            '           Global context ......................... 13',
            '               Global trade ....................... 13',
            '           Effectiveness of policy frameworks in mitigating',
            '           the impact of shocks ................... 140',
            'Boxes      1.1     How much will AI affect growth? ... 8',
        ]);

        $entries = (new TocParser())->parse([9 => $page]);

        $this->assertSame(['Foreword', 'Summary', 'Global context', 'Global trade', 'Effectiveness of policy frameworks in mitigating the impact of shocks'], array_column($entries, 'title'));
        $this->assertSame('xv', $entries[0]['page_label']);
        // "Chapter 1" side tab is blanked, keeping the entry's own column.
        $this->assertSame($entries[2]['indent'], $entries[1]['indent']);
        $this->assertGreaterThan($entries[2]['indent'], $entries[3]['indent']);
    }

    public function test_table_parser_serializes_rows_against_the_header_in_force_with_column_groups()
    {
        $lines = [
            'TABLE 2.10 South Asia economy forecasts                                Percentage-point differences',
            '(Real GDP growth, percent)                                             from January projections',
            '                       2023      2024      2026f                       2026f',
            'Calendar-year basis',
            '    Sri Lanka          -2.0      5.0       3.6                         0.1',
            'Fiscal-year basis 1  2023/24   2024/25   2026/27f                    2026/27f',
            '    India 2            7.2       7.1       6.6                         0.1',
            'Source: World Bank.',
            'Note: e = estimate; f = forecast.',
        ];

        $table = (new TableParser())->parse($lines);

        $this->assertSame('TABLE 2.10 South Asia economy forecasts', $table['caption']);
        $this->assertSame('(Real GDP growth, percent)', $table['subtitle']);
        $this->assertContains(
            'Sri Lanka | 2023: -2.0 | 2024: 5.0 | 2026f: 3.6 | [Percentage-point differences from January projections] 2026f: 0.1',
            $table['rows']
        );
        // Header switched to fiscal years mid-table; footnote marker dropped from the label.
        $this->assertContains(
            'India | 2023/24: 7.2 | 2024/25: 7.1 | 2026/27f: 6.6 | [Percentage-point differences from January projections] 2026/27f: 0.1',
            $table['rows']
        );
        $this->assertSame('Source: World Bank. Note: e = estimate; f = forecast.', $table['notes']);
    }

    public function test_table_regions_end_at_their_notes_not_at_following_body_text()
    {
        $page = implode("\n", [
            'Some body text before.',
            'TABLE 1.1 Real GDP',
            '              2023    2024',
            'World         2.8     2.9',
            'Source: World Bank.',
            '1. Footnote one that wraps',
            'onto a second line.',
            'Growth in the region is projected to slow sharply this year.',
        ]);

        $regions = (new TableParser())->regions($page);

        $this->assertCount(1, $regions);
        $this->assertSame('Table 1.1', $regions[0]['label']);
        $this->assertStringContainsString('onto a second line.', implode("\n", $regions[0]['lines']));
        $this->assertStringNotContainsString('Growth in the region', implode("\n", $regions[0]['lines']));
    }

    public function test_structured_chunker_keeps_chunks_inside_sections_and_builds_breadcrumbs()
    {
        $blocks = [
            ['type' => 'section', 'level' => 1, 'kind' => 'chapter', 'label' => 'Chapter 2', 'title' => 'Regional Outlooks', 'page' => 69],
            ['type' => 'section', 'level' => 2, 'kind' => 'section', 'label' => null, 'title' => 'South Asia', 'page' => 96],
            ['type' => 'section', 'level' => 3, 'kind' => 'section', 'label' => null, 'title' => 'Outlook', 'page' => 96],
            ['type' => 'text', 'page' => 96, 'text' => 'Growth in the region is expected to soften to 6.3 percent.'],
            ['type' => 'table', 'page' => 98, 'label' => 'Table 2.10', 'caption' => 'TABLE 2.10 South Asia economy forecasts',
                'subtitle' => '', 'rows' => ['India | 2026/27f: 6.6'], 'notes' => 'Source: World Bank.'],
            ['type' => 'text', 'page' => 98, 'text' => 'Inflation is projected to rise.'],
            ['type' => 'section', 'level' => 2, 'kind' => 'section', 'label' => null, 'title' => 'Sub-Saharan Africa', 'page' => 99],
            ['type' => 'text', 'page' => 99, 'text' => 'Growth is forecast to edge down to 4 percent.'],
        ];

        $result = (new StructuredChunker(1000, 0, 1500))->chunk($blocks, 'GEP');
        $paths = array_map(function ($chunk) use ($result) {
            return $result['sections'][$chunk['section']]['path'];
        }, $result['chunks']);

        // The South Asia text flows around the table into one chunk, the
        // table is its own leaf, and Sub-Saharan Africa never shares a chunk.
        $this->assertContains('Chapter 2: Regional Outlooks > South Asia > Outlook > Table 2.10: South Asia economy forecasts', $paths);
        $southAsiaText = array_values(array_filter($result['chunks'], function ($c) {
            return $c['kind'] === 'text' && strpos($c['content'], 'soften') !== false;
        }))[0];
        $this->assertStringContainsString('Inflation is projected to rise.', $southAsiaText['content']);
        $this->assertStringNotContainsString('edge down', $southAsiaText['content']);
        $this->assertSame('Chapter 2: Regional Outlooks > Sub-Saharan Africa', end($paths));

        $table = array_values(array_filter($result['chunks'], function ($c) {
            return $c['kind'] === 'table';
        }))[0];
        $this->assertSame("Table 2.10: South Asia economy forecasts\nIndia | 2026/27f: 6.6\nSource: World Bank.", $table['content']);
    }
}
