<?php

namespace App\Services\Rag\Ingestion;

use App\Exceptions\RagException;
use App\Models\RagChunk;
use App\Models\RagDocument;
use App\Models\RagSection;
use App\Services\Rag\Embeddings\EmbeddingProviderInterface;
use App\Services\Rag\Structure\GlyphRepair;
use App\Services\Rag\Structure\StructuredChunker;
use App\Services\Rag\Structure\StructureParser;
use App\Services\Rag\Structure\TableParser;
use App\Services\Rag\Structure\TocParser;
use App\Services\Rag\VectorMath;
use Illuminate\Support\Facades\DB;

/**
 * extract -> clean -> chunk -> embed -> store, for one source file, with
 * two chunking strategies:
 *
 *  - "flat": fixed-size, sentence-aligned chunks over the whole text.
 *    Fine for short documents (e.g. the 82-page GPO report).
 *  - "structured": recovers the heading tree (chapters, sections, boxes,
 *    tables, figures), chunks within sections, rebuilds tables row by row
 *    and attaches a section breadcrumb to every chunk. Built for long,
 *    structured reports (e.g. the 200-page World Bank GEP).
 *
 * Idempotent per (file SHA-256, strategy): re-ingesting an unchanged file
 * with the same strategy does nothing unless forced. Embeddings are cached
 * by text hash, so a forced re-ingest only pays for chunks whose text
 * changed. Embedding happens before the DB transaction so a slow/failed
 * API call never leaves a half-written document behind.
 */
class DocumentIngestor
{
    const STRATEGIES = ['flat', 'structured'];

    /**
     * @var \App\Services\Rag\Ingestion\PdfTextExtractor
     */
    protected $extractor;

    /**
     * @var \App\Services\Rag\Ingestion\TextCleaner
     */
    protected $cleaner;

    /**
     * @var \App\Services\Rag\Embeddings\EmbeddingProviderInterface
     */
    protected $embeddings;

    public function __construct(PdfTextExtractor $extractor, TextCleaner $cleaner, EmbeddingProviderInterface $embeddings)
    {
        $this->extractor = $extractor;
        $this->cleaner = $cleaner;
        $this->embeddings = $embeddings;
    }

    /**
     * Extract, clean and chunk without embedding or storing anything.
     *
     * @param  string  $path
     * @param  string  $strategy
     * @param  string  $title
     * @return array{pages: int, chunks: array<int, array>, sections: array<int, array>, stats: array}
     */
    public function prepare(string $path, string $strategy = 'flat', string $title = ''): array
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new RagException("Unknown chunking strategy \"{$strategy}\" - expected: " . implode(', ', self::STRATEGIES));
        }

        $prepared = $strategy === 'structured'
            ? $this->prepareStructured($path, $title)
            : $this->prepareFlat($path);

        if (empty($prepared['chunks'])) {
            throw new RagException('The document produced no text chunks.');
        }

        return $prepared;
    }

    /**
     * @param  string  $path
     * @return array
     */
    protected function prepareFlat(string $path): array
    {
        $pages = $this->extractor->extractPages($path);
        $chunker = new TextChunker((int) config('rag.chunk_size'), (int) config('rag.chunk_overlap'));

        $chunks = [];
        foreach ($chunker->chunk($this->cleaner->cleanPages($pages)) as $chunk) {
            $chunks[] = $chunk + ['kind' => 'text', 'section' => null, 'page_label' => null];
        }

        return ['pages' => count($pages), 'chunks' => $chunks, 'sections' => [], 'stats' => []];
    }

    /**
     * @param  string  $path
     * @param  string  $title
     * @return array
     */
    protected function prepareStructured(string $path, string $title): array
    {
        $pages = $this->extractor->extractPages($path);
        $tablePages = $this->extractor->extractPagesInMode($path, 'table');
        $layoutPages = $this->extractor->extractPagesInMode($path, 'layout');

        $glyphs = new GlyphRepair();
        $repaired = $glyphs->isNeeded($pages);
        if ($repaired) {
            $pages = array_map([$glyphs, 'repair'], $pages);
            $tablePages = $tablePages === null ? null : array_map([$glyphs, 'repair'], $tablePages);
        }

        $toc = $layoutPages === null ? [] : (new TocParser())->parse($layoutPages);
        $parser = new StructureParser($this->cleaner, new TableParser());
        $blocks = $parser->parse($pages, $tablePages, $toc);

        $config = config('rag.structured');
        $result = (new StructuredChunker((int) $config['chunk_size'], (int) $config['chunk_overlap'], (int) $config['table_chunk_size']))
            ->chunk($blocks, $title ?: pathinfo($path, PATHINFO_FILENAME));

        foreach ($result['chunks'] as $i => $chunk) {
            $result['chunks'][$i]['page_label'] = $this->pageLabel($parser->pageLabels, $chunk['page_start'], $chunk['page_end']);
        }

        return [
            'pages' => count($pages),
            'chunks' => $result['chunks'],
            'sections' => $result['sections'],
            'stats' => $parser->stats + ['glyph_repair' => $repaired],
        ];
    }

    /**
     * @param  string  $path
     * @param  string  $title
     * @param  string  $strategy
     * @param  bool  $force
     * @param  callable|null  $progress  fn(string $message)
     * @return array{document: \App\Models\RagDocument, skipped: bool}
     *
     * @throws \App\Exceptions\RagException
     */
    public function ingest(string $path, string $title, string $strategy = 'flat', bool $force = false, ?callable $progress = null): array
    {
        $progress = $progress ?: function () {
        };

        if (! is_file($path)) {
            throw new RagException("File not found: {$path}");
        }

        $checksum = hash_file('sha256', $path);
        $existing = RagDocument::where('checksum', $checksum)->where('strategy', $strategy)->first();

        $upToDate = $existing
            && $existing->embedding_model === $this->embeddings->model()
            && (int) $existing->embedding_dimensions === $this->embeddings->dimensions();

        if ($upToDate && ! $force) {
            return ['document' => $existing, 'skipped' => true];
        }

        $progress("Extracting and chunking text ({$strategy})...");
        $prepared = $this->prepare($path, $strategy, $title);
        $chunks = $prepared['chunks'];
        $sections = $prepared['sections'];

        $texts = [];
        foreach ($chunks as $chunk) {
            $sectionPath = $chunk['section'] !== null ? $sections[$chunk['section']]['path'] : null;
            $texts[] = RagChunk::searchableTextFor($chunk['content'], $sectionPath);
        }

        $vectors = $this->embedWithCache($texts, $title, $progress, $prepared['pages'], count($sections));

        $document = DB::transaction(function () use ($existing, $title, $path, $checksum, $strategy, $prepared, $chunks, $sections, $vectors) {
            if ($existing) {
                $existing->delete();
            }

            $document = RagDocument::create([
                'title' => $title,
                'source_path' => $path,
                'checksum' => $checksum,
                'strategy' => $strategy,
                'page_count' => $prepared['pages'],
                'chunk_count' => count($chunks),
                'embedding_model' => $this->embeddings->model(),
                'embedding_dimensions' => $this->embeddings->dimensions(),
            ]);

            // Ordinals are in document order, so a parent is always
            // inserted before its children.
            $sectionIds = [];
            foreach ($sections as $ordinal => $section) {
                $sectionIds[$ordinal] = RagSection::create([
                    'rag_document_id' => $document->id,
                    'parent_id' => $section['parent'] !== null ? $sectionIds[$section['parent']] : null,
                    'ordinal' => $ordinal,
                    'level' => $section['level'],
                    'kind' => $section['kind'],
                    'label' => $section['label'],
                    'title' => mb_substr($section['title'], 0, 500),
                    'path' => $section['path'],
                    'page_start' => $section['page_start'],
                    'page_end' => $section['page_end'],
                ])->id;
            }

            $now = now();
            $rows = [];
            foreach ($chunks as $index => $chunk) {
                $hasSection = $chunk['section'] !== null;
                $rows[] = [
                    'rag_document_id' => $document->id,
                    'rag_section_id' => $hasSection ? $sectionIds[$chunk['section']] : null,
                    'chunk_index' => $index,
                    'kind' => $chunk['kind'],
                    'section_path' => $hasSection ? $sections[$chunk['section']]['path'] : null,
                    'page_start' => $chunk['page_start'],
                    'page_end' => $chunk['page_end'],
                    'page_label' => $chunk['page_label'],
                    'content' => $chunk['content'],
                    'embedding' => VectorMath::pack($vectors[$index]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 100) as $batch) {
                RagChunk::insert($batch);
            }

            return $document;
        });

        return ['document' => $document, 'skipped' => false];
    }

    /**
     * Embed texts, reusing cached vectors for any text (with the same
     * model, dimensions and document title) embedded before.
     *
     * @param  array<int, string>  $texts
     * @param  string  $title
     * @param  callable  $progress
     * @param  int  $pages
     * @param  int  $sections
     * @return array<int, array<int, float>>
     */
    protected function embedWithCache(array $texts, string $title, callable $progress, int $pages, int $sections): array
    {
        $prefix = $this->embeddings->model() . '|' . $this->embeddings->dimensions() . '|' . $title . '|';
        $hashes = array_map(function ($text) use ($prefix) {
            return hash('sha256', $prefix . $text);
        }, $texts);

        $cached = [];
        foreach (array_chunk(array_unique($hashes), 500) as $batch) {
            foreach (DB::table('rag_embedding_cache')->whereIn('hash', $batch)->get() as $row) {
                $cached[$row->hash] = VectorMath::unpack($row->embedding);
            }
        }

        $missing = [];
        foreach ($hashes as $i => $hash) {
            if (! isset($cached[$hash])) {
                $missing[$hash] = $texts[$i];
            }
        }

        $progress(sprintf(
            '%d pages -> %d sections, %d chunks. Embedding %d new (%d cached) with %s...',
            $pages,
            $sections,
            count($texts),
            count($missing),
            count($texts) - count($missing),
            $this->embeddings->model()
        ));

        // Embed and cache batch by batch, so a run that dies part-way
        // (free-tier quotas) resumes from where it stopped next time.
        $batchSize = max(1, (int) config('rag.embeddings.batch_size', 50));
        $done = 0;
        foreach (array_chunk($missing, $batchSize, true) as $batch) {
            $fresh = $this->embeddings->embedDocuments(array_values($batch), $title);
            $now = now();
            $rows = [];
            foreach (array_keys($batch) as $i => $hash) {
                $cached[$hash] = $fresh[$i];
                $rows[] = ['hash' => $hash, 'embedding' => VectorMath::pack($fresh[$i]), 'created_at' => $now];
            }
            DB::table('rag_embedding_cache')->insertOrIgnore($rows);
            $done += count($batch);
            if (count($missing) > $batchSize) {
                $progress("  embedded {$done}/" . count($missing));
            }
        }

        return array_map(function ($hash) use ($cached) {
            return $cached[$hash];
        }, $hashes);
    }

    /**
     * @param  array<int, string|null>  $labels
     * @param  int  $start
     * @param  int  $end
     * @return string|null
     */
    protected function pageLabel(array $labels, int $start, int $end): ?string
    {
        $first = $labels[$start] ?? null;
        $last = $labels[$end] ?? null;

        if ($first === null) {
            return null;
        }

        return ($last === null || $last === $first) ? $first : $first . '-' . $last;
    }
}
