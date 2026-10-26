<?php

namespace App\Services\Rag\Embeddings;

use App\Exceptions\RagException;
use App\Services\Rag\VectorMath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gemini embeddings via models/{model}:batchEmbedContents.
 *
 * Uses task types (RETRIEVAL_DOCUMENT for chunks, RETRIEVAL_QUERY for
 * questions) so the model optimises each side for retrieval, and requests
 * a truncated output dimensionality, which Gemini does not normalise - so
 * every vector is L2-normalised here. Rate-limit (429) and server (5xx)
 * errors are retried with exponential backoff, honouring the API's
 * suggested retry delay when it gives one.
 */
class GeminiEmbeddingProvider implements EmbeddingProviderInterface
{
    /**
     * @var array
     */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function dimensions(): int
    {
        return (int) $this->config['dimensions'];
    }

    public function embedDocuments(array $texts, ?string $title = null): array
    {
        $vectors = [];
        $batchSize = max(1, min(100, (int) ($this->config['batch_size'] ?? 50)));

        foreach (array_chunk($texts, $batchSize) as $batch) {
            $requests = [];
            foreach ($batch as $text) {
                $request = $this->request($text, 'RETRIEVAL_DOCUMENT');
                if ($title !== null && $title !== '') {
                    $request['title'] = $title;
                }
                $requests[] = $request;
            }

            foreach ($this->batchEmbed($requests) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function embedQuery(string $query): array
    {
        return $this->batchEmbed([$this->request($query, 'RETRIEVAL_QUERY')])[0];
    }

    /**
     * @param  string  $text
     * @param  string  $taskType
     * @return array
     */
    protected function request(string $text, string $taskType): array
    {
        return [
            'model' => 'models/' . $this->model(),
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
            'outputDimensionality' => $this->dimensions(),
        ];
    }

    /**
     * @param  array  $requests
     * @return array<int, array<int, float>>
     *
     * @throws \App\Exceptions\RagException
     */
    protected function batchEmbed(array $requests): array
    {
        if (! filled($this->config['api_key'] ?? null)) {
            throw new RagException('No embedding API key configured (RAG_EMBEDDING_API_KEY / GEMINI_API_KEY).');
        }

        $endpoint = rtrim($this->config['url'], '/') . '/models/' . $this->model() . ':batchEmbedContents';
        $maxRetries = (int) ($this->config['max_retries'] ?? 4);

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::timeout((int) ($this->config['timeout'] ?? 60))
                    ->withHeaders(['x-goog-api-key' => $this->config['api_key']])
                    ->post($endpoint, ['requests' => $requests]);
            } catch (ConnectionException $e) {
                if ($attempt < $maxRetries) {
                    $this->backoff($attempt, null);
                    continue;
                }
                throw new RagException('Embedding request failed: ' . $e->getMessage(), 0, $e);
            }

            if ($response->successful()) {
                break;
            }

            $retryable = $response->status() === 429 || $response->status() >= 500;
            if ($retryable && $attempt < $maxRetries) {
                Log::info('Embedding request throttled/failed, retrying', [
                    'status' => $response->status(),
                    'attempt' => $attempt + 1,
                ]);
                $this->backoff($attempt, $this->suggestedRetryDelay($response->json()));
                continue;
            }

            throw new RagException(sprintf(
                'Embedding request failed (HTTP %d): %s',
                $response->status(),
                (string) $response->json('error.message', $response->body())
            ));
        }

        $embeddings = $response->json('embeddings');

        if (! is_array($embeddings) || count($embeddings) !== count($requests)) {
            throw new RagException('Embedding response did not contain one vector per input.');
        }

        $vectors = [];
        foreach ($embeddings as $embedding) {
            $values = $embedding['values'] ?? null;
            if (! is_array($values) || empty($values)) {
                throw new RagException('Embedding response contained an empty vector.');
            }
            $vectors[] = VectorMath::normalize(array_map('floatval', $values));
        }

        return $vectors;
    }

    /**
     * Gemini's 429 body includes a RetryInfo detail like {"retryDelay": "17s"}.
     *
     * @param  mixed  $body
     * @return int|null  seconds
     */
    protected function suggestedRetryDelay($body): ?int
    {
        foreach ((array) ($body['error']['details'] ?? []) as $detail) {
            if (isset($detail['retryDelay']) && preg_match('/^(\d+(?:\.\d+)?)s$/', $detail['retryDelay'], $m)) {
                return (int) ceil((float) $m[1]);
            }
        }

        return null;
    }

    /**
     * @param  int  $attempt
     * @param  int|null  $suggestedSeconds
     * @return void
     */
    protected function backoff(int $attempt, ?int $suggestedSeconds): void
    {
        $seconds = $suggestedSeconds !== null ? min($suggestedSeconds, 60) : min(2 ** $attempt, 30);
        sleep(max(1, $seconds));
    }
}
