<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\Ingestion\TextCleaner;
use Tests\TestCase;

class TextCleanerTest extends TestCase
{
    public function test_removes_running_headers_and_page_numbers_but_keeps_body_text()
    {
        $pages = [];
        for ($i = 1; $i <= 6; $i++) {
            $body = '';
            for ($line = 1; $line <= 10; $line++) {
                $body .= "Paragraph {$line} on page {$i}.\n\n";
            }
            $pages[$i] = "2019 ANNUAL REPORT | U.S. GOVERNMENT PUBLISHING OFFICE\n\n{$body}{$i}";
        }

        $cleaned = (new TextCleaner())->cleanPages($pages);

        $this->assertStringStartsWith('Paragraph 1 on page 3.', $cleaned[3]);
        $this->assertStringEndsWith('Paragraph 10 on page 3.', $cleaned[3]);
        $this->assertStringNotContainsString('ANNUAL REPORT', implode(' ', $cleaned));
    }

    public function test_never_strips_repeated_lines_from_the_middle_of_a_page()
    {
        $pages = [];
        for ($i = 1; $i <= 6; $i++) {
            $pages[$i] = "Top {$i}a.\nTop {$i}b.\nTop {$i}c.\nTop {$i}d.\nTop {$i}e.\n"
                . "Total\n"
                . "Bottom {$i}a.\nBottom {$i}b.\nBottom {$i}c.\nBottom {$i}d.\nBottom {$i}e.";
        }

        $cleaned = (new TextCleaner())->cleanPages($pages);

        $this->assertStringContainsString("\n\nTotal\n\n", $cleaned[2]);
    }

    public function test_keeps_parenthetical_unit_notes_even_when_repeated()
    {
        $pages = [];
        for ($i = 1; $i <= 6; $i++) {
            $pages[$i] = "(Dollars in Thousands)\n\nRevenue {$i}00";
        }

        $cleaned = (new TextCleaner())->cleanPages($pages);

        $this->assertStringContainsString('(Dollars in Thousands)', $cleaned[2]);
    }

    public function test_does_not_strip_repeated_lines_in_very_short_documents()
    {
        $cleaned = (new TextCleaner())->cleanPages([1 => "Title\n\nOne.", 2 => "Title\n\nTwo."]);

        $this->assertStringContainsString('Title', $cleaned[1]);
    }

    public function test_rejoins_lines_wrapped_mid_sentence_including_across_blank_lines()
    {
        $cleaned = (new TextCleaner())->cleanPages([
            1 => "For the Clerk of the House, GPO\n\npublishes the documents required\n\nby Congress.\nNew paragraph here.",
        ]);

        $this->assertSame(
            "For the Clerk of the House, GPO publishes the documents required by Congress.\n\nNew paragraph here.",
            $cleaned[1]
        );
    }

    public function test_repairs_end_of_line_hyphenation()
    {
        $cleaned = (new TextCleaner())->cleanPages([1 => "the imple-\nmentation of the plan."]);

        $this->assertSame('the implementation of the plan.', $cleaned[1]);
    }

    public function test_normalizes_ligatures_and_dot_leaders()
    {
        $cleaner = new TextCleaner();

        $this->assertSame('Melissa Hatfield', $cleaner->normalize("Melissa Hat\u{FB01}eld"));
        $this->assertSame('Mission 4', trim($cleaner->normalize('Mission..............4')));
    }
}
