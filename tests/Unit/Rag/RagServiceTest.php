<?php

namespace Tests\Unit\Rag;

use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Services\LLM\LlmService;
use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\ContextBuilder;
use App\Services\Rag\Retrieval\HybridRetriever;
use Tests\TestCase;

class RagServiceTest extends TestCase
{
    public function test_normalizes_native_model_citation_markers()
    {
        $service = $this->service($this->createMock(LlmService::class), $this->createMock(HybridRetriever::class));

        $this->assertSame(
            'It was $675.9 million [1][3] and grew [2].',
            $service->normalizeCitations("It was \$675.9 million \u{3010}1\u{3011}\u{3010}3\u{3011} and grew \u{3010}2\u{2020}L4-L9\u{3011}.")
        );
    }

    public function test_answer_injects_numbered_excerpts_and_marks_cited_sources()
    {
        $document = new RagDocument(['title' => 'GPO Annual Report 2019']);
        $results = [];
        foreach ([[6, 'As of September 30, 2019, GPO employed 1,662 staff.'], [11, 'In 1980, total GPO employment was 6,450.']] as $i => $row) {
            $chunk = new RagChunk(['content' => $row[1], 'page_start' => $row[0], 'page_end' => $row[0], 'chunk_index' => $i]);
            $chunk->id = $i + 1;
            $chunk->setRelation('document', $document);
            $results[] = ['chunk' => $chunk, 'score' => 0.03 - $i * 0.01];
        }

        $retriever = $this->createMock(HybridRetriever::class);
        $retriever->expects($this->once())->method('retrieve')->with('How many people work at GPO?')->willReturn($results);

        $llm = $this->createMock(LlmService::class);
        $llm->expects($this->once())->method('complete')
            ->with($this->callback(function (array $messages) {
                $prompt = end($messages)['content'];

                return strpos($prompt, "[1] (GPO Annual Report 2019, p. 6)\nAs of September 30, 2019") !== false
                    && strpos($prompt, '[2] (GPO Annual Report 2019, p. 11)') !== false
                    && substr($prompt, -strlen('Question: How many people work at GPO?')) === 'Question: How many people work at GPO?';
            }))
            ->willReturn(['content' => 'GPO employed 1,662 staff [1].', 'provider' => 'gemini', 'provider_label' => 'Gemini', 'model' => 'm']);

        $answer = $this->service($llm, $retriever)->answerQuestion('How many people work at GPO?');

        $this->assertSame('GPO employed 1,662 staff [1].', $answer['content']);
        $this->assertCount(2, $answer['sources']);
        $this->assertTrue($answer['sources'][0]['cited']);
        $this->assertSame('p. 6', $answer['sources'][0]['pages']);
        $this->assertFalse($answer['sources'][1]['cited']);
    }

    public function test_first_turn_is_searched_verbatim_without_a_rewrite_call()
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects($this->never())->method('complete');

        $service = $this->service($llm, $this->createMock(HybridRetriever::class));

        $this->assertSame('What is XPub?', $service->searchQuery('What is XPub?', []));
    }

    public function test_follow_up_is_rewritten_into_a_standalone_query()
    {
        $llm = $this->createMock(LlmService::class);
        $llm->expects($this->once())->method('complete')
            ->willReturn(['content' => "\"How many passports has GPO produced since 2005?\"\n", 'provider' => 'gemini', 'provider_label' => 'Gemini', 'model' => 'm']);

        $service = $this->service($llm, $this->createMock(HybridRetriever::class));

        $query = $service->searchQuery('And since 2005?', [
            ['role' => 'user', 'content' => 'How many passports did GPO produce in FY 2019?'],
            ['role' => 'assistant', 'content' => '15.2 million [1].'],
        ]);

        $this->assertSame('How many passports has GPO produced since 2005?', $query);
    }

    protected function service(LlmService $llm, HybridRetriever $retriever): RagService
    {
        return new RagService($llm, $retriever, new ContextBuilder($retriever));
    }
}
