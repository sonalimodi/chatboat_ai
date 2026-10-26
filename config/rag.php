<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document Ingestion
    |--------------------------------------------------------------------------
    |
    | PDFs are read page by page so every chunk can cite the page(s) it came
    | from. Poppler's `pdftotext` is used when available (it's ~500x faster
    | than the pure-PHP parser on large, image-heavy reports and handles
    | multi-column layouts better); smalot/pdfparser is the fallback so
    | ingestion still works on machines without Poppler. Set
    | RAG_PDFTOTEXT_BINARY to an absolute path if it isn't on PATH, or to an
    | empty string to always use the PHP parser.
    |
    */

    'pdftotext_binary' => env('RAG_PDFTOTEXT_BINARY', 'pdftotext'),

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    |
    | Chunks are built from whole sentences, packed up to chunk_size
    | characters, with roughly chunk_overlap characters of trailing sentences
    | repeated at the start of the next chunk so facts that straddle a
    | boundary are still retrievable. ~1,200 characters (~300 tokens) keeps
    | each chunk focused on one topic - annual reports are dense with
    | distinct figures, and smaller chunks retrieve them more precisely.
    |
    */

    'chunk_size' => (int) env('RAG_CHUNK_SIZE', 1200),

    'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 200),

    /*
    |--------------------------------------------------------------------------
    | Structured (section-aware) Ingestion
    |--------------------------------------------------------------------------
    |
    | `rag:ingest --strategy=structured` (the default for new ingests)
    | recovers the document's heading tree and chunks within sections.
    | Chunks are smaller than flat ones because context expansion (below)
    | re-attaches neighbouring text at answer time: small chunks retrieve
    | precisely, expansion restores the surrounding context. Table chunks
    | are row groups that each repeat the table's caption.
    |
    */

    'default_strategy' => env('RAG_DEFAULT_STRATEGY', 'structured'),

    'structured' => [
        'chunk_size' => (int) env('RAG_STRUCTURED_CHUNK_SIZE', 1000),
        'chunk_overlap' => (int) env('RAG_STRUCTURED_CHUNK_OVERLAP', 150),
        'table_chunk_size' => (int) env('RAG_TABLE_CHUNK_SIZE', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Management
    |--------------------------------------------------------------------------
    |
    | After retrieval, each hit from a structured document is widened with
    | up to `neighbor_window` adjacent chunks from the same section, hits
    | that touch are merged into one passage, and passages are added in
    | relevance order until `max_chars` is reached. This bounds the prompt
    | (free-tier models have small per-minute token limits) while giving
    | the LLM whole paragraphs rather than fragments.
    |
    */

    'context' => [
        'max_chars' => (int) env('RAG_CONTEXT_MAX_CHARS', 12000),
        'neighbor_window' => (int) env('RAG_NEIGHBOR_WINDOW', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Gemini's embedding model, reusing GEMINI_API_KEY. Vectors are truncated
    | to `dimensions` (Matryoshka truncation) and L2-normalised before
    | storage. Changing the model or dimensions requires re-ingesting
    | (`php artisan rag:ingest <file> --force`) - documents record which
    | model embedded them and retrieval skips mismatched ones.
    |
    */

    'embeddings' => [
        'api_key' => env('RAG_EMBEDDING_API_KEY', env('GEMINI_API_KEY')),
        'model' => env('RAG_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 768),
        'url' => env('RAG_EMBEDDING_URL', env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta')),
        'batch_size' => (int) env('RAG_EMBEDDING_BATCH_SIZE', 50),
        'timeout' => (int) env('RAG_EMBEDDING_TIMEOUT', 60),
        'max_retries' => (int) env('RAG_EMBEDDING_MAX_RETRIES', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | Hybrid retrieval: dense (cosine over embeddings) and lexical (BM25)
    | rankings are each cut to `candidates`, then merged with Reciprocal
    | Rank Fusion. Dense search handles paraphrase ("how many people work
    | there" -> "employed 1,662 staff"); BM25 rescues exact terms and
    | numbers that embeddings blur (acronyms like "TDR", "FECA", years).
    | `top_k` fused chunks are passed to the LLM, always including the top
    | `guaranteed_per_retriever` hits of each ranking (RRF alone can bury a
    | chunk only one retriever finds, even at rank 1).
    |
    */

    'top_k' => (int) env('RAG_TOP_K', 8),

    'guaranteed_per_retriever' => (int) env('RAG_GUARANTEED_PER_RETRIEVER', 2),

    /*
    | Questions that name an element - "Box 3.1", "Table 2.10", "Figure 1.4",
    | "Annex 4.2", "Chapter 3" - get a third ranking in the fusion: the
    | chunks inside that element (ordered by dense similarity), at this
    | weight. Sections are labelled at ingestion, so this is exact.
    */

    'reference_weight' => (float) env('RAG_REFERENCE_WEIGHT', 2.0),

    'candidates' => (int) env('RAG_CANDIDATES', 20),

    'rrf_k' => (int) env('RAG_RRF_K', 60),

    'dense_weight' => (float) env('RAG_DENSE_WEIGHT', 1.0),

    'lexical_weight' => (float) env('RAG_LEXICAL_WEIGHT', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Query Rewriting
    |--------------------------------------------------------------------------
    |
    | For follow-up turns ("and what about 2018?"), the conversation is first
    | condensed into a standalone search query by the LLM so retrieval sees
    | the full intent. The original wording is still what the LLM answers.
    | Costs one extra (short) LLM call per follow-up turn.
    |
    */

    'rewrite_queries' => filter_var(env('RAG_REWRITE_QUERIES', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Grounded Answer Prompt
    |--------------------------------------------------------------------------
    */

    'not_found_message' => 'I couldn\'t find that information in the ingested documents.',

    'system_prompt' => env('RAG_SYSTEM_PROMPT', 'You are a precise assistant that answers questions about the documents '
        . 'provided to you as numbered excerpts. Rules: '
        . '(1) Answer using ONLY the information in the excerpts. Do not use outside knowledge, and do not guess. '
        . '(2) Cite the excerpt(s) that support each claim with bracketed numbers like [1] or [2][4], placed right after the claim. '
        . '(3) Quote figures (amounts, counts, percentages, dates) exactly as written in the excerpts and keep their units and fiscal year. '
        . '(4) If the excerpts do not contain the answer, reply with exactly: "I couldn\'t find that information in the ingested documents." '
        . 'If they only partly answer it, give the part that is supported and say what is missing. '
        . '(5) Each excerpt is labelled with its document, section path (e.g. "Chapter 2 > South Asia > Outlook") and page; '
        . 'use the section path to know which region, country or topic the text is about. Table rows are written as '
        . '"Row | column: value | ..."; a bracketed [group] label applies to the columns after it, and "e"/"f" after a year '
        . 'mean estimate/forecast. When excerpts disagree, prefer the one whose section matches the question. '
        . '(6) Use the earlier conversation only to understand what a follow-up question refers to. '
        . '(7) Be concise: lead with the direct answer, then any brief supporting detail.'),

];
