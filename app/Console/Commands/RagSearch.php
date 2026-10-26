<?php

namespace App\Console\Commands;

use App\Services\Rag\Retrieval\HybridRetriever;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RagSearch extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rag:search
        {query : What to search for}
        {--k= : Number of chunks to return}
        {--document=* : Restrict to these document ids}';

    /**
     * @var string
     */
    protected $description = 'Show which chunks hybrid retrieval returns for a query (no LLM call)';

    public function handle(HybridRetriever $retriever): int
    {
        $k = $this->option('k') ? (int) $this->option('k') : null;
        $documents = $this->option('document') ? array_map('intval', $this->option('document')) : null;
        $results = $retriever->retrieve($this->argument('query'), $k, $documents);

        if (empty($results)) {
            $this->warn('No results - is anything ingested? (php artisan rag:list)');

            return 1;
        }

        $references = HybridRetriever::elementReferences($this->argument('query'));
        if (! empty($references)) {
            $this->line('Element references routed: ' . implode(', ', $references));
        }

        $this->table(
            ['#', 'Pages', 'RRF', 'Dense (cos)', 'BM25', 'Ref', 'Section', 'Text'],
            array_map(function ($r, $i) {
                $chunk = $r['chunk'];

                return [
                    $i + 1,
                    $chunk->pageLabel(),
                    number_format($r['score'], 4),
                    $r['dense_rank'] ? $r['dense_rank'] . ' (' . number_format($r['dense_score'], 3) . ')' : '-',
                    $r['lexical_rank'] ?: '-',
                    $r['reference_rank'] ?? '-',
                    Str::limit((string) $chunk->section_path ?: '-', 55),
                    Str::limit(preg_replace('/\s+/u', ' ', $chunk->content), 60),
                ];
            }, $results, array_keys($results))
        );

        return 0;
    }
}
