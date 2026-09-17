<?php

namespace App\Services\LLM;

use App\Exceptions\LlmServiceException;
use App\Models\ChatSession;
use App\Services\LLM\Contracts\LlmProviderInterface;
use App\Services\LLM\Providers\GeminiProvider;
use App\Services\LLM\Providers\GroqProvider;
use App\Services\LLM\Providers\OpenRouterProvider;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates chat completion across multiple LLM providers with automatic
 * fallback: providers are tried strictly in the order given by
 * config('llm.order'), skipping any that aren't enabled/configured. The
 * first provider to succeed wins; every failure is logged (without leaking
 * secrets) and the next provider is tried. If every provider fails, a single
 * generic, user-safe error is raised.
 *
 * This class - and the controller that calls it - never talks to a
 * provider's HTTP API directly; that logic lives entirely in the
 * App\Services\LLM\Providers\* classes behind LlmProviderInterface.
 */
class LlmService
{
    /**
     * Maps a config('llm.order') key to its provider class.
     *
     * @var array<string, class-string<LlmProviderInterface>>
     */
    protected static $providerClasses = [
        'gemini' => GeminiProvider::class,
        'groq' => GroqProvider::class,
        'openrouter' => OpenRouterProvider::class,
    ];

    /**
     * @var array<int, LlmProviderInterface>
     */
    protected $providers;

    public function __construct()
    {
        $this->providers = $this->resolveProviders();
    }

    /**
     * Generate an assistant reply for a chat session, trying each
     * configured provider in priority order until one succeeds.
     *
     * @param  \App\Models\ChatSession  $chatSession
     * @param  string|null  $preferredProviderKey  If given and configured,
     *         this provider is tried first (this is how the frontend's
     *         model picker actually takes effect); the rest of the normal
     *         fallback order still applies afterward if it fails.
     * @return array{content: string, provider: string, provider_label: string, model: string|null}
     *
     * @throws \App\Exceptions\LlmServiceException
     */
    public function generateReply(ChatSession $chatSession, ?string $preferredProviderKey = null): array
    {
        if (empty($this->providers)) {
            Log::error('No LLM providers are enabled/configured. Check LLM_PROVIDERS and each provider\'s *_ENABLED/*_API_KEY settings.');

            throw new LlmServiceException($this->unavailableMessage());
        }

        $messages = $this->buildConversationPayload($chatSession);

        foreach ($this->orderedProviders($preferredProviderKey) as $provider) {
            $result = $provider->chat($messages);

            if (! empty($result['success'])) {
                return [
                    'content' => $result['content'],
                    'provider' => $provider->key(),
                    'provider_label' => $provider->label(),
                    'model' => isset($result['model']) ? $result['model'] : null,
                ];
            }

            Log::warning('LLM provider failed: ' . $provider->key(), [
                'provider' => $provider->key(),
                'model' => isset($result['model']) ? $result['model'] : null,
                'status' => isset($result['status']) ? $result['status'] : null,
                'error_type' => isset($result['error_type']) ? $result['error_type'] : null,
                'error' => isset($result['error']) ? $result['error'] : null,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        throw new LlmServiceException($this->unavailableMessage());
    }

    /**
     * The list of currently usable providers/models, for the frontend's
     * model picker - only non-sensitive metadata (no API keys). The first
     * entry is whichever provider the automatic fallback order would try
     * first by default.
     *
     * @return array<int, array{key: string, label: string, description: string, badge: string, model: string|null}>
     */
    public function availableModels(): array
    {
        return array_values(array_map(function (LlmProviderInterface $provider) {
            return [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'description' => $provider->description(),
                'badge' => $provider->badge(),
                'model' => $provider->modelName(),
            ];
        }, $this->providers));
    }

    /**
     * The configured providers, reordered (if possible) so the preferred
     * one is tried first while every other provider keeps its relative
     * fallback order.
     *
     * @param  string|null  $preferredProviderKey
     * @return array<int, LlmProviderInterface>
     */
    protected function orderedProviders(?string $preferredProviderKey): array
    {
        if ($preferredProviderKey === null) {
            return $this->providers;
        }

        $preferred = [];
        $rest = [];

        foreach ($this->providers as $provider) {
            if ($provider->key() === $preferredProviderKey) {
                $preferred[] = $provider;
            } else {
                $rest[] = $provider;
            }
        }

        return array_merge($preferred, $rest);
    }

    /**
     * Instantiate, in configured order, only the providers that are both
     * listed in LLM_PROVIDERS and fully configured (enabled + has an API
     * key + has a model).
     *
     * @return array<int, LlmProviderInterface>
     */
    protected function resolveProviders(): array
    {
        $order = config('llm.order', []);
        $providers = [];

        foreach ($order as $key) {
            if (! isset(static::$providerClasses[$key])) {
                continue;
            }

            $providerConfig = config("llm.providers.{$key}", []);
            $providerClass = static::$providerClasses[$key];
            /** @var LlmProviderInterface $provider */
            $provider = new $providerClass($providerConfig);

            if ($provider->isConfigured()) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Build the neutral, OpenAI-style message list sent to every provider:
     * the system prompt first, then the most recent messages of this
     * conversation only (oldest first). Every provider (including whichever
     * one ends up serving the fallback) receives the exact same context, so
     * follow-up questions keep working regardless of which provider answers.
     *
     * Context-window management here is a simple message-count cutoff
     * (LLM_MAX_HISTORY_MESSAGES). Production applications with very long
     * conversations should track actual token counts and/or summarize older
     * turns instead of dropping them outright.
     *
     * @param  \App\Models\ChatSession  $chatSession
     * @return array<int, array{role: string, content: string}>
     */
    protected function buildConversationPayload(ChatSession $chatSession): array
    {
        $maxHistory = config('llm.max_history_messages');

        $recentMessages = $chatSession->messages()
            ->where('role', '!=', 'system')
            ->orderByDesc('created_at')
            ->take($maxHistory)
            ->get()
            ->sortBy('created_at')
            ->values();

        $payload = [
            ['role' => 'system', 'content' => config('llm.system_prompt')],
        ];

        foreach ($recentMessages as $message) {
            $payload[] = [
                'role' => $message->role,
                'content' => $this->contentWithAttachment($message),
            ];
        }

        return $payload;
    }

    /**
     * A message's text, with its attachment's extracted text (if any)
     * appended for the LLM's benefit. The stored `chat_messages.message`
     * itself is never modified - this only affects what's sent to the
     * provider, so replaying this same historical message on a later turn
     * (still within LLM_MAX_HISTORY_MESSAGES) keeps including the file's
     * content, letting follow-up questions about it keep working.
     *
     * @param  \App\Models\ChatMessage  $message
     * @return string
     */
    protected function contentWithAttachment($message): string
    {
        $attachment = $message->attachment;

        if (! $attachment) {
            return $message->message;
        }

        if (! $attachment->extracted_text) {
            return $message->message
                . "\n\n(The user attached a file named \"{$attachment->original_filename}\", but no readable "
                . "text could be extracted from it - it may be an image-only/scanned PDF or empty. Tell the "
                . "user this directly instead of guessing at its contents.)";
        }

        // Framed explicitly as already-extracted, inline text - smaller
        // models otherwise sometimes pattern-match on "attached file" /
        // "PDF" and reflexively reply "I can't access files" even though
        // the full text is right there in the prompt.
        return $message->message
            . "\n\n--- Begin content already extracted from the user's attached file \"{$attachment->original_filename}\" ---\n"
            . $attachment->extracted_text
            . "\n--- End of file content. Read and use it directly; do not claim you cannot view the file. ---";
    }

    protected function unavailableMessage(): string
    {
        return 'Sorry, the AI service is temporarily unavailable. Please try again later.';
    }
}
