<?php

namespace App\Console\Commands;

use App\Models\RagDocument;
use Illuminate\Console\Command;

class RagList extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rag:list {--delete= : Remove the document with this id (and its chunks)}';

    /**
     * @var string
     */
    protected $description = 'List (or remove) documents in the RAG knowledge base';

    public function handle(): int
    {
        if ($id = $this->option('delete')) {
            $document = RagDocument::find($id);
            if (! $document) {
                $this->error("No document with id {$id}.");

                return 1;
            }
            $document->delete();
            $this->info("Deleted #{$id} \"{$document->title}\".");

            return 0;
        }

        $documents = RagDocument::orderBy('id')->get();

        if ($documents->isEmpty()) {
            $this->line('No documents ingested yet. Run: php artisan rag:ingest <file.pdf>');

            return 0;
        }

        $this->table(
            ['ID', 'Title', 'Strategy', 'Pages', 'Sections', 'Chunks', 'Embedding', 'Ingested'],
            $documents->map(function (RagDocument $d) {
                return [
                    $d->id,
                    $d->title,
                    $d->strategy,
                    $d->page_count,
                    $d->sections()->count(),
                    $d->chunk_count,
                    $d->embedding_model . ' / ' . $d->embedding_dimensions,
                    $d->updated_at->toDateTimeString(),
                ];
            })->all()
        );

        return 0;
    }
}
