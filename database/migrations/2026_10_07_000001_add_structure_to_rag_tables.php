<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStructureToRagTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('rag_documents', function (Blueprint $table) {
            // "flat" (fixed-size chunks over the whole text) or "structured"
            // (section-aware chunks with a heading hierarchy). The same file
            // may be ingested once per strategy, e.g. to compare them.
            $table->string('strategy', 20)->default('flat')->after('checksum');
            $table->dropUnique(['checksum']);
            $table->unique(['checksum', 'strategy']);
        });

        // The document's heading tree: chapters > sections > subsections,
        // plus boxes / figures / tables / annexes as typed nodes.
        Schema::create('rag_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rag_document_id')
                ->constrained('rag_documents')
                ->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('rag_sections')
                ->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->unsignedTinyInteger('level');
            // chapter | section | box | figure | table | annex | front
            $table->string('kind', 20);
            // "Box 3.1", "Table 1.1", "Chapter 2" - for direct references.
            $table->string('label')->nullable();
            $table->string('title', 500);
            // Breadcrumb from the root, e.g. "Chapter 2: Regional Outlooks > South Asia > Outlook".
            $table->text('path');
            $table->unsignedInteger('page_start');
            $table->unsignedInteger('page_end');
            $table->timestamps();

            $table->index(['rag_document_id', 'ordinal']);
        });

        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->foreignId('rag_section_id')->nullable()->after('rag_document_id')
                ->constrained('rag_sections')
                ->nullOnDelete();
            // text | table | figure | box
            $table->string('kind', 20)->default('text')->after('chunk_index');
            $table->text('section_path')->nullable()->after('kind');
            // Printed page labels ("74", "xvii") when they differ from PDF page numbers.
            $table->string('page_label', 40)->nullable()->after('page_end');
        });

        // Embeddings keyed by (model, dimensions, title, text) hash, so
        // re-ingesting after a chunking change only pays for chunks whose
        // text actually changed. Free-tier embedding quotas are per item.
        Schema::create('rag_embedding_cache', function (Blueprint $table) {
            $table->char('hash', 64)->primary();
            $table->mediumText('embedding');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('rag_embedding_cache');

        Schema::table('rag_chunks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rag_section_id');
            $table->dropColumn(['kind', 'section_path', 'page_label']);
        });

        Schema::dropIfExists('rag_sections');

        Schema::table('rag_documents', function (Blueprint $table) {
            $table->dropUnique(['checksum', 'strategy']);
            $table->unique('checksum');
            $table->dropColumn('strategy');
        });
    }
}
