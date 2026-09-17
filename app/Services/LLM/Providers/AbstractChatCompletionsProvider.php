<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LlmProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Base class for providers that speak the OpenAI-compatible
 * "/chat/completions" wire format (Groq, OpenRouter, and any other
 * OpenAI-compatible endpoint). Concrete subclasses only need to supply
 * key(), label(), description() and badge() - everything else (payload
 * shape, auth, error handling) is shared.
 */
abstract class AbstractChatCompletionsProvider implements LlmProviderInterface
{
    /**
     * @var array{enabled?: bool, api_key?: string|null, model?: string|null, url?: string|null}
     */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function modelName(): ?string
    {
        return isset($this->config['model']) ? $this->config['model'] : null;
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && filled($this->config['api_key'] ?? null)
            && filled($this->config['model'] ?? null);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array
     */
    public function chat(array $messages): array
    {
        $model = $this->config['model'];
        $endpoint = rtrim($this->config['url'], '/') . '/chat/completions';

        try {
            $response = Http::timeout(config('llm.timeout'))
                ->withToken($this->config['api_key'])
                ->post($endpoint, [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => false,
                ]);
        } catch (ConnectionException $e) {
            return $this->failure('connection_error', $e->getMessage(), null);
        } catch (Throwable $e) {
            return $this->failure('unexpected_error', $e->getMessage(), null);
        }

        if ($response->failed()) {
            return $this->failure(
                $this->errorType($response->status()),
                $response->body(),
                $response->status()
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            return $this->failure('empty_response', $response->body(), $response->status());
        }

        return [
            'success' => true,
            'provider' => $this->key(),
            'model' => $model,
            'content' => trim($content),
        ];
    }

    protected function errorType(int $status): string
    {
        if ($status === 401 || $status === 403) {
            return 'invalid_api_key';
        }
        if ($status === 404) {
            return 'model_not_found';
        }
        if ($status === 429) {
            return 'rate_limited';
        }
        if ($status >= 500) {
            return 'server_error';
        }

        return 'request_failed';
    }

    protected function failure(string $errorType, string $error, ?int $status): array
    {
        return [
            'success' => false,
            'provider' => $this->key(),
            'model' => isset($this->config['model']) ? $this->config['model'] : null,
            'error' => $error,
            'error_type' => $errorType,
            'status' => $status,
        ];
    }
}
