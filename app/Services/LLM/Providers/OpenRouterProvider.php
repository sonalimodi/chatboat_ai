<?php

namespace App\Services\LLM\Providers;

/**
 * OpenRouter (https://openrouter.ai) - a unified, OpenAI-compatible gateway
 * in front of many providers/models, several of which are free to use
 * (their IDs carry a ":free" suffix, e.g. "openai/gpt-oss-20b:free").
 */
class OpenRouterProvider extends AbstractChatCompletionsProvider
{
    public function key(): string
    {
        return 'openrouter';
    }

    public function label(): string
    {
        return 'OpenRouter';
    }

    public function description(): string
    {
        return 'Unified gateway to free, open-source models.';
    }

    public function badge(): string
    {
        return 'Free';
    }
}
