<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a stage of the RAG pipeline (extraction, embedding,
 * retrieval) cannot complete. Messages are operator-facing (CLI / logs);
 * the chat UI shows its own generic message instead.
 */
class RagException extends RuntimeException
{
}
