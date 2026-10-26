<?php

namespace App\Console\Commands;

use App\Exceptions\RagException;
use App\Services\Rag\Ingestion\DocumentIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RagIngest extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rag:ingest
        {path : PDF, .txt or .md file to ingest (absolute, or relative to the project root)}
        {--title= : Display/citation title (defaults to the file name)}
        {--strategy= : "structured" (section-aware; default) or "flat" (fixed-size chunks)}
        {--force : Re-ingest even if this exact file was already ingested with this strategy}
        {--dry-run : Extract and chunk only - no embedding, nothing stored}
        {--dump= : With --dry-run, write the section tree and every chunk to this file for inspection}';

    /**
     * @var string
     */
    protected $description = 'Extract, chunk, embed and store a document in the RAG knowledge base';

    public function handle(DocumentIngestor $ingestor): int
    {
        $path = $this->argument('path');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }

        $title = $this->option('title')
            ?: Str::title(str_replace(['_', '-'], ' ', pathinfo($path, PATHINFO_FILENAME)));
        $strategy = $this->option('strategy') ?: config('rag.default_strategy', 'structured');

        try {
            if ($this->option('dry-run')) {
                return $this->dryRun($ingestor, $path, $strategy, $title);
            }

            $started = microtime(true);
            $result = $ingestor->ingest($path, $title, $strategy, (bool) $this->option('force'), function ($message) {
                $this->line($message);
            });
        } catch (RagException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $document = $result['document'];

        if ($result['skipped']) {
            $this->info("Already ingested as #{$document->id} \"{$document->title}\" ({$document->strategy}, {$document->chunk_count} chunks). Use --force to re-ingest.");

            return 0;
        }

        $this->info(sprintf(
            'Ingested #%d "%s" (%s): %d pages, %d sections, %d chunks in %.1fs.',
            $document->id,
            $document->title,
            $document->strategy,
            $document->page_count,
            $document->sections()->count(),
            $document->chunk_count,
            microtime(true) - $started
        ));

        return 0;
    }

    protected function dryRun(DocumentIngestor $ingestor, string $path, string $strategy, string $title): int
    {
        $prepared = $ingestor->prepare($path, $strategy, $title);
        $lengths = array_map(function ($chunk) {
            return mb_strlen($chunk['content']);
        }, $prepared['chunks']);
        sort($lengths);

        $kinds = array_count_values(array_column($prepared['chunks'], 'kind'));
        ksort($kinds);

        $this->table(['Strategy', 'Pages', 'Sections', 'Chunks', 'By kind', 'Min / median / max chars'], [[
            $strategy,
            $prepared['pages'],
            count($prepared['sections']),
            count($lengths),
            urldecode(http_build_query($kinds, '', ', ')),
            $lengths[0] . ' / ' . $lengths[intdiv(count($lengths), 2)] . ' / ' . end($lengths),
        ]]);

        if (! empty($prepared['stats'])) {
            $this->line('Structure: ' . urldecode(http_build_query(array_map(function ($v) {
                return is_bool($v) ? ($v ? 'yes' : 'no') : $v;
            }, $prepared['stats']), '', ', ')));
        }

        if ($dump = $this->option('dump')) {
            $out = '';
            if (! empty($prepared['sections'])) {
                $out .= "=== SECTION TREE ===\n";
                foreach ($prepared['sections'] as $section) {
                    $out .= str_repeat('  ', $section['level'] - 1) . "[{$section['kind']}] "
                        . ($section['label'] ? $section['label'] . ': ' : '') . $section['title']
                        . " (pages {$section['page_start']}-{$section['page_end']})\n";
                }
                $out .= "\n=== CHUNKS ===\n";
            }
            foreach ($prepared['chunks'] as $i => $chunk) {
                $path = $chunk['section'] !== null ? $prepared['sections'][$chunk['section']]['path'] : '-';
                $out .= "### chunk {$i} [{$chunk['kind']}] (pages {$chunk['page_start']}-{$chunk['page_end']}) {$path}\n{$chunk['content']}\n\n";
            }
            file_put_contents($dump, $out);
            $this->info("Written to {$dump}");
        }

        return 0;
    }
}
