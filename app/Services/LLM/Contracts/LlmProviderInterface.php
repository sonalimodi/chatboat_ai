<?php

namespace App\Services\LLM\Contracts;

/**
 * Common interface every LLM provider (Gemini, Groq, OpenRouter, ...) must
 * implement so LlmService can try them interchangeably in priority order.
 */
interface LlmProviderInterface
{
    /**
     * Short machine key for this provider, e.g. "gemini". Used in config,
     * logs, and the fallback order.
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable name for non-sensitive UI display, e.g. "Gemini".
     *
     * @return string
     */
    public function label(): string;

    /**
     * One-line, non-sensitive description for the model picker,
     * e.g. "Google's fast, general-purpose model".
     *
     * @return string
     */
    public function description(): string;

    /**
     * Short badge word for the model picker, e.g. "Recommended", "Fast",
     * "Free".
     *
     * @return string
     */
    public function badge(): string;

    /**
     * The underlying model identifier currently configured for this
     * provider (e.g. "gemini-3.6-flash"), for non-sensitive UI display.
     *
     * @return string|null
     */
    public function modelName(): ?string;

    /**
     * Whether this provider has everything it needs (enabled + API key +
     * model) to be attempted at all.
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Send a chat completion request.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     *         Neutral, OpenAI-style message list: role is one of
     *         "system", "user", "assistant", oldest first. Providers are
     *         responsible for translating this into their own wire format.
     *
     * @return array{
     *     success: bool,
     *     provider: string,
     *     model: string|null,
     *     content?: string,
     *     error?: string,
     *     error_type?: string,
     *     status?: int|null
     * }
     *         On success: success=true and content holds the assistant reply.
     *         On failure: success=false and error/error_type/status describe
     *         what went wrong, for logging only - never shown to the user
     *         verbatim.
     */
    public function chat(array $messages): array;
}
