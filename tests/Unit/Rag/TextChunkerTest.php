<?php

namespace Tests\Unit\Rag;

use App\Services\Rag\Ingestion\TextChunker;
use Tests\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_short_text_becomes_a_single_chunk_with_its_page()
    {
        $chunks = (new TextChunker(1200, 200))->chunk([7 => 'GPO employed 1,662 staff. That is all.']);

        $this->assertCount(1, $chunks);
        $this->assertSame(7, $chunks[0]['page_start']);
        $this->assertSame(7, $chunks[0]['page_end']);
        $this->assertSame('GPO employed 1,662 staff. That is all.', $chunks[0]['content']);
    }

    public function test_chunks_respect_size_never_split_sentences_and_overlap()
    {
        $sentences = [];
        for ($i = 1; $i <= 40; $i++) {
            $sentences[] = "Sentence number {$i} talks about the Federal Depository Library Program in detail.";
        }

        $chunks = (new TextChunker(400, 100))->chunk([1 => implode(' ', $sentences)]);

        $this->assertGreaterThan(5, count($chunks));

        foreach ($chunks as $i => $chunk) {
            $this->assertLessThanOrEqual(400, mb_strlen($chunk['content']));
            // Every chunk starts at a sentence start and ends at a sentence end.
            $this->assertMatchesRegularExpression('/^Sentence number \d+/', $chunk['content']);
            $this->assertStringEndsWith('in detail.', $chunk['content']);

            if ($i > 0) {
                // Overlap: the next chunk begins with the previous chunk's last sentence.
                preg_match_all('/Sentence number \d+/', $chunks[$i - 1]['content'], $prev);
                $this->assertStringStartsWith(end($prev[0]), $chunk['content']);
            }
        }

        // Nothing lost: every sentence appears somewhere.
        $all = implode(' ', array_column($chunks, 'content'));
        for ($i = 1; $i <= 40; $i++) {
            $this->assertStringContainsString("Sentence number {$i} talks", $all);
        }
    }

    public function test_does_not_split_on_common_abbreviations()
    {
        $chunks = (new TextChunker(200, 0))->chunk([
            1 => 'The U.S. Code is printed once every six years by the Office. It is nearly 60,000 pages long and is produced in XPub now.',
        ]);

        $this->assertStringContainsString('The U.S. Code is printed', $chunks[0]['content']);
    }

    public function test_tracks_page_ranges_and_stitches_sentences_across_pages()
    {
        $chunks = (new TextChunker(1200, 0))->chunk([
            3 => 'We produced more than 15 million passports and 4 million',
            4 => 'other secure credentials in FY 2019.',
        ]);

        $this->assertCount(1, $chunks);
        $this->assertSame(3, $chunks[0]['page_start']);
        $this->assertSame(4, $chunks[0]['page_end']);
        $this->assertSame('We produced more than 15 million passports and 4 million other secure credentials in FY 2019.', $chunks[0]['content']);
    }

    public function test_hard_wraps_a_single_oversized_sentence()
    {
        $table = trim(str_repeat('675,891 167,442 59,463 ', 60));

        $chunks = (new TextChunker(300, 0))->chunk([1 => $table]);

        $this->assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(300, mb_strlen($chunk['content']));
        }
    }
}
