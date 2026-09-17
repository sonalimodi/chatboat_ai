<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by App\Services\LLM\LlmService when every configured LLM provider
 * has failed. The message on this exception is always safe to display to
 * the end user - no internal details, stack traces, API keys, or
 * configuration values.
 */
class LlmServiceException extends Exception
{
    //
}
