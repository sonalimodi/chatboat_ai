<?php

namespace Tests\Unit\Rag;

use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Services\Rag\Embeddings\EmbeddingProviderInterface;
use App\Services\Rag\Retrieval\Bm25Index;
use App\Services\Rag\Retrieval\ContextBuilder;
use App\Services\Rag\Retrieval\HybridRetriever;
use App\Services\Rag\VectorMath;
use Tests\TestCase;

class RetrievalTest extends TestCase
{
    public function test_vectors_round_trip_through_packed_storage()
    {
        $vector = VectorMath::normalize([3.0, 4.0, 0.0]);

        $this->assertEqualsWithDelta([0.6, 0.8, 0.0], $vector, 1e-9);
        $this->assertEqualsWithDelta($vector, VectorMath::unpack(VectorMath::pack($vector)), 1e-6);
        $this->assertEqualsWithDelta(1.0, VectorMath::dot($vector, $vector), 1e-9);
    }

    public function test_tokenizer_normalizes_numbers_plurals_and_stopwords()
    {
        $this->assertSame(['passport', 'gpo', 'produce', '1662'], Bm25Index::tokenize('How many passports did GPO produce? 1,662'));
        $this->assertSame(['15.2', 'million', 'library'], Bm25Index::tokenize('15.2 million libraries'));
    }

    public function test_bm25_ranks_the_document_with_rare_query_terms_first()
    {
        $index = new Bm25Index([
            'a' => 'GPO produces publications for Congress and Federal agencies.',
            'b' => 'govinfo earned Trustworthy Digital Repository certification under ISO 16363.',
            'c' => 'GPO publications are distributed to depository libraries.',
        ]);

        $results = $index->search('Which ISO standard was govinfo certified against?', 3);

        $this->assertSame('b', array_key_first($results));
    }

    public function test_reciprocal_rank_fusion_rewards_agreement_between_rankings()
    {
        $fused = HybridRetriever::reciprocalRankFusion([[1, 2, 3], [3, 1, 4]], [1.0, 1.0], 60);

        $this->assertSame([1, 3, 2, 4], array_keys($fused));
    }

    public function test_guarantee_keeps_a_top_hit_found_by_only_one_retriever()
    {
        // Dense ranks chunk 9 first; lexical never sees it. Plain RRF would
        // rank it below chunks both lists agree on and cut it at k=2.
        $rankings = [[9, 1, 2, 3], [1, 2, 3, 4]];
        $fused = HybridRetriever::reciprocalRankFusion($rankings, [1.0, 1.0]);

        $this->assertNotContains(9, array_slice(array_keys($fused), 0, 2));

        $selected = HybridRetriever::selectWithGuarantee($fused, $rankings, 1, 2);

        $this->assertSame([1, 9], array_keys($selected));
    }

    public function test_hybrid_rank_combines_dense_and_lexical_signals()
    {
        $document = new RagDocument(['title' => 'Test Report']);
        $chunks = collect([
            $this->chunk(1, 'GPO employed 1,662 staff at the end of the fiscal year.', [1, 0, 0], $document),
            $this->chunk(2, 'The Federal Register is published Monday through Friday.', [0, 1, 0], $document),
            $this->chunk(3, 'Passports are produced for the Department of State.', [0, 0, 1], $document),
        ]);

        $retriever = new HybridRetriever($this->createMock(EmbeddingProviderInterface::class));

        // Query vector points at chunk 1 (paraphrase, no shared keywords).
        $results = $retriever->rank($chunks, 'how many people work there', [1, 0, 0], 2);
        $this->assertSame(1, $results[0]['chunk']->id);
        $this->assertSame(1, $results[0]['dense_rank']);

        // Exact keyword match wins even when the dense signal is neutral
        // (plain RRF - with k=1 the guarantee would reserve the only slot
        // for the arbitrary dense #1).
        config(['rag.guaranteed_per_retriever' => 0]);
        $neutral = VectorMath::normalize([1, 1, 1]);
        $results = $retriever->rank($chunks, 'Federal Register', $neutral, 1);
        $this->assertSame(2, $results[0]['chunk']->id);
        $this->assertSame(1, $results[0]['lexical_rank']);
    }

    public function test_element_references_are_parsed_and_routed_to_that_element()
    {
        $this->assertSame(['Box 3.1', 'Table 2.10'], HybridRetriever::elementReferences('Compare box 3.1 with Table 2.10'));
        $this->assertSame(['Figure B1.2.1'], HybridRetriever::elementReferences('What does fig. b1.2.1 show?'));

        $document = new RagDocument(['title' => 'GEP']);
        $chunks = collect([
            $this->chunk(1, 'Spreads rose sharply after the revelation.', [1, 0, 0], $document, 'Chapter 3 > Box 3.1: The costs of hidden debt'),
            $this->chunk(2, 'Spreads rose sharply in many economies.', [0.9, 0.1, 0], $document, 'Chapter 3 > Introduction'),
            $this->chunk(3, 'Unrelated passage.', [0, 0, 1], $document, 'Chapter 4 > Introduction'),
        ]);
        config(['rag.guaranteed_per_retriever' => 0]);

        $results = (new HybridRetriever($this->createMock(EmbeddingProviderInterface::class)))
            ->rank($chunks, 'What does Box 3.1 say about spreads?', VectorMath::normalize([0.9, 0.1, 0]), 1);

        $this->assertSame(1, $results[0]['chunk']->id);
        $this->assertSame(1, $results[0]['reference_rank']);
    }

    public function test_context_builder_widens_hits_merges_neighbours_and_respects_the_budget()
    {
        $document = new RagDocument(['title' => 'GEP']);
        $c1 = $this->chunk(11, 'First sentence. Shared overlap sentence.', [1, 0, 0], $document, 'S', 0, 7);
        $c2 = $this->chunk(12, 'Shared overlap sentence. Second chunk text.', [1, 0, 0], $document, 'S', 1, 7);
        $c3 = $this->chunk(13, 'Third chunk text.', [1, 0, 0], $document, 'S', 2, 7);
        $other = $this->chunk(20, str_repeat('Long unrelated text. ', 40), [0, 1, 0], $document, 'T', 9, 8);
        $byIndex = [0 => $c1, 1 => $c2, 2 => $c3, 9 => $other];

        $retriever = $this->createMock(HybridRetriever::class);
        $retriever->method('neighbors')->willReturnCallback(function (RagChunk $chunk, int $window) use ($byIndex) {
            $result = [];
            foreach ($byIndex as $index => $c) {
                if ($c->rag_section_id === $chunk->rag_section_id && abs($index - $chunk->chunk_index) <= $window) {
                    $result[$index] = $c;
                }
            }

            return $result;
        });

        $builder = new ContextBuilder($retriever);
        $passages = $builder->build([['chunk' => $c2, 'score' => 0.9], ['chunk' => $c3, 'score' => 0.8], ['chunk' => $other, 'score' => 0.7]], 200, 1);

        // c2 widened to c1..c3 (one passage, overlap removed); c3 already
        // used; the long unrelated passage doesn't fit the 200-char budget.
        $this->assertCount(1, $passages);
        $this->assertSame([0, 1, 2], array_keys($passages[0]['chunks']));
        $this->assertSame('First sentence. Shared overlap sentence. Second chunk text.' . "\n\n" . 'Third chunk text.', $passages[0]['content']);
    }

    protected function chunk(int $id, string $content, array $vector, RagDocument $document, ?string $sectionPath = null, ?int $index = null, ?int $sectionId = null): RagChunk
    {
        $chunk = new RagChunk([
            'content' => $content,
            'page_start' => $id,
            'page_end' => $id,
            'section_path' => $sectionPath,
            'chunk_index' => $index ?? $id,
            'rag_section_id' => $sectionId,
            'rag_document_id' => 1,
        ]);
        $chunk->id = $id;
        $chunk->setAttribute('vector', VectorMath::normalize($vector));
        $chunk->setRelation('document', $document);

        return $chunk;
    }
}
