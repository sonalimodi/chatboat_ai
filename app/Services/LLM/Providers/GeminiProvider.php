<?php

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LlmProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google Gemini (generateContent) provider.
 *
 * Gemini's wire format differs from the OpenAI-style "messages" convention
 * used by the other providers: it expects "user"/"model" roles (not
 * "assistant") inside "contents", and the system prompt is sent separately
 * as "systemInstruction" rather than as a message in the list.
 */
class GeminiProvider implements LlmProviderInterface
{
    /**
     * @var array{enabled?: bool, api_key?: string|null, model?: string|null, url?: string|null}
     */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function key(): string
    {
        return 'gemini';
    }

    public function label(): string
    {
        return 'Gemini';
    }

    public function description(): string
    {
        return "Google's fast, general-purpose multimodal model.";
    }

    public function badge(): string
    {
        return 'Recommended';
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

    public function chat(array $messages): array
    {
        $model = $this->config['model'];
        $endpoint = rtrim($this->config['url'], '/') . '/models/' . $model . ':generateContent';

        $systemText = collect($messages)
            ->where('role', 'system')
            ->pluck('content')
            ->implode("\n\n");

        $contents = collect($messages)
            ->where('role', '!=', 'system')
            ->map(function (array $m) {
                return [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ];
            })
            ->values()
            ->all();

        $payload = ['contents' => $contents];

        if ($systemText !== '') {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemText]],
            ];
        }

        try {
            $response = Http::timeout(config('llm.timeout'))
                ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
                ->post($endpoint, $payload);
        } catch (ConnectionException $e) {
            return $this->failure('connection_error', $e->getMessage(), null);
        } catch (Throwable $e) {
            return $this->failure('unexpected_error', $e->getMessage(), null);
        }

        if ($response->failed()) {
            // Gemini returns 400 INVALID_ARGUMENT (reason: API_KEY_INVALID)
            // for a bad key, rather than 401/403 like most APIs.
            $reason = $response->json('error.details.0.reason');
            $errorType = $reason === 'API_KEY_INVALID'
                ? 'invalid_api_key'
                : $this->errorType($response->status());

            return $this->failure($errorType, $response->body(), $response->status());
        }

        $parts = $response->json('candidates.0.content.parts');
        $text = is_array($parts)
            ? trim(collect($parts)->pluck('text')->filter()->implode(''))
            : '';

        if ($text === '') {
            return $this->failure('empty_response', $response->body(), $response->status());
        }

        return [
            'success' => true,
            'provider' => $this->key(),
            'model' => $model,
            'content' => $text,
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
