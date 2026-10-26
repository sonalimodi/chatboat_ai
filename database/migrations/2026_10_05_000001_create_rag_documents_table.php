<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRagDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('source_path');
            // SHA-256 of the source file, so re-running ingestion on an
            // unchanged file is a no-op unless --force is given.
            $table->char('checksum', 64)->unique();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            // Which model/dimensions produced this document's vectors -
            // query vectors must come from the same space to be comparable.
            $table->string('embedding_model');
            $table->unsignedSmallInteger('embedding_dimensions');
            $table->timestamps();
        });

        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rag_document_id')
                ->constrained('rag_documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('page_start');
            $table->unsignedInteger('page_end');
            $table->text('content');
            // Base64 of packed little-endian float32s: ~4 KB per 768-dim
            // vector, versus ~15 KB as a JSON array of decimals.
            $table->mediumText('embedding');
            $table->timestamps();

            $table->unique(['rag_document_id', 'chunk_index']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('rag_chunks');
        Schema::dropIfExists('rag_documents');
    }
}
