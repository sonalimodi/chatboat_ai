<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Configuration for every LLM provider the app knows how to talk to.
    | Each one maps directly to a class in app/Services/LLM/Providers.
    | A provider is only ever attempted if "enabled" is true AND it has
    | both an api_key and a model configured (see each provider's
    | isConfigured() method) - so it's safe to leave a provider's block
    | filled in but disabled, or empty and disabled.
    |
    | Never hard-code API keys here - they must come from env().
    |
    */

    'providers' => [

        'gemini' => [
            'enabled' => env('GEMINI_ENABLED', true),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'groq' => [
            'enabled' => env('GROQ_ENABLED', false),
            'api_key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
            'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
        ],

        'openrouter' => [
            'enabled' => env('OPENROUTER_ENABLED', false),
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'openai/gpt-oss-20b:free'),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Fallback Order
    |--------------------------------------------------------------------------
    |
    | Comma-separated provider keys, tried strictly left to right. The first
    | enabled + fully configured provider in this list that returns a
    | successful response wins; if it fails (timeout, rate limit, invalid
    | key, unavailable model, any other request failure), the next one in
    | the list is tried automatically. Providers not listed here are never
    | used, even if configured.
    |
    */

    'order' => array_values(array_filter(
        array_map('trim', explode(',', env('LLM_PROVIDERS', 'gemini,groq,openrouter')))
    )),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Applied per-provider, per-attempt - so a worst case with all three
    | providers configured and all timing out takes up to ~3x this value.
    |
    */

    'timeout' => (int) env('LLM_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Max History Messages
    |--------------------------------------------------------------------------
    |
    | Simple context-window management: only the most recent N messages
    | (user + assistant) of a conversation are sent to whichever provider
    | ends up handling the request. This keeps prompts small and fast, and
    | guarantees the fallback provider sees the exact same context the
    | primary one would have. For very long conversations, a production
    | system should switch to token-based context management and/or
    | summarization of older turns instead of a flat message-count cutoff.
    |
    */

    'max_history_messages' => (int) env('LLM_MAX_HISTORY_MESSAGES', 20),

    /*
    |--------------------------------------------------------------------------
    | System Prompt
    |--------------------------------------------------------------------------
    |
    | Sent as the system message on every request, to every provider, so
    | whichever one answers knows how to behave and how to use the
    | conversation history that follows it.
    |
    */

    'system_prompt' => env('LLM_SYSTEM_PROMPT', 'You are a helpful AI assistant. Answer clearly and accurately. '
        . 'Use the previous conversation to understand follow-up questions and resolve references such as '
        . '"it", "that", or "the second one". If the user changes the topic, answer the new topic directly '
        . 'while still keeping the rest of the conversation in mind. When a message contains a block delimited '
        . 'by "Begin content already extracted from the user\'s attached file" and "End of file content", that '
        . 'text has already been extracted from a real file the user uploaded and is provided to you directly - '
        . 'read and use it as you would any other text in the conversation. Never respond by saying you cannot '
        . 'open, view, or access a file when its content has been provided to you this way.'),

];
