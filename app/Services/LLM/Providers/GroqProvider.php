<?php

namespace App\Services\LLM\Providers;

/**
 * Groq (https://console.groq.com) - free-tier, very low-latency inference
 * over open models (Llama, GPT-OSS, Qwen, etc). Fully OpenAI-compatible.
 */
class GroqProvider extends AbstractChatCompletionsProvider
{
    public function key(): string
    {
        return 'groq';
    }

    public function label(): string
    {
        return 'Groq';
    }

    public function description(): string
    {
        return 'Ultra-fast inference on open-weight models.';
    }

    public function badge(): string
    {
        return 'Fast';
    }
}
