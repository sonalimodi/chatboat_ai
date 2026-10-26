<?php

namespace App\Services\Rag;

use App\Exceptions\LlmServiceException;
use App\Models\ChatSession;
use App\Models\RagDocument;
use App\Services\LLM\LlmService;
use App\Services\Rag\Retrieval\ContextBuilder;
use App\Services\Rag\Retrieval\HybridRetriever;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Retrieval-augmented answering over the ingested documents.
 *
 *   conversation -> (follow-up?) standalone query rewrite -> hybrid
 *   retrieval -> context building (neighbour expansion, merging, budget)
 *   -> numbered excerpts, each labelled with document, section path and
 *   page, injected into the final user turn -> LLM (with the normal
 *   provider fallback) -> answer with [n] citations + the source list
 *   those numbers refer to.
 *
 * Generation goes through LlmService::complete(), so RAG answers get the
 * same Gemini -> Groq -> OpenRouter fallback as ordinary chat.
 */
class RagService
{
    /**
     * @var \App\Services\LLM\LlmService
     */
    protected $llm;

    /**
     * @var \App\Services\Rag\Retrieval\HybridRetriever
     */
    protected $retriever;

    /**
     * @var \App\Services\Rag\Retrieval\ContextBuilder
     */
    protected $context;

    public function __construct(LlmService $llm, HybridRetriever $retriever, ContextBuilder $context)
    {
        $this->llm = $llm;
        $this->retriever = $retriever;
        $this->context = $context;
    }

    /**
     * Whether anything has been ingested to answer from.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return RagDocument::query()->exists();
    }

    /**
     * Titles of the ingested documents, for the UI.
     *
     * @return array<int, string>
     */
    public function documentTitles(): array
    {
        return RagDocument::orderBy('title')->distinct()->pluck('title')->all();
    }

    /**
     * Answer the latest user message of a chat session from the knowledge
     * base, using the session's history for follow-up context.
     *
     * @param  \App\Models\ChatSession  $chatSession
     * @param  string|null  $preferredProviderKey
     * @return array{content: string, provider: string, provider_label: string, model: string|null, query: string, sources: array}
     *
     * @throws \App\Exceptions\LlmServiceException
     * @throws \App\Exceptions\RagException
     */
    public function answerForSession(ChatSession $chatSession, ?string $preferredProviderKey = null): array
    {
        $messages = $this->llm->buildConversationPayload($chatSession, config('rag.system_prompt'));

        return $this->answerMessages($messages, $preferredProviderKey);
    }

    /**
     * Answer a single standalone question (CLI / evaluation).
     *
     * @param  string  $question
     * @param  string|null  $preferredProviderKey
     * @param  array<int, int>|null  $documentIds  restrict retrieval to these documents
     * @return array
     */
    public function answerQuestion(string $question, ?string $preferredProviderKey = null, ?array $documentIds = null): array
    {
        return $this->answerMessages([
            ['role' => 'system', 'content' => config('rag.system_prompt')],
            ['role' => 'user', 'content' => $question],
        ], $preferredProviderKey, $documentIds);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages  system first, latest user message last
     * @param  string|null  $preferredProviderKey
     * @param  array<int, int>|null  $documentIds  restrict retrieval to these documents
     * @return array
     */
    public function answerMessages(array $messages, ?string $preferredProviderKey = null, ?array $documentIds = null): array
    {
        $lastIndex = count($messages) - 1;
        $question = $messages[$lastIndex]['content'];
        $history = array_values(array_filter(array_slice($messages, 0, $lastIndex), function ($m) {
            return $m['role'] !== 'system';
        }));

        $query = $this->searchQuery($question, $history);
        $results = $this->retriever->retrieve($query, null, $documentIds);
        $passages = $this->context->build($results);

        if (empty($passages)) {
            return [
                'content' => config('rag.not_found_message'),
                'provider' => null,
                'provider_label' => null,
                'model' => null,
                'query' => $query,
                'sources' => [],
            ];
        }

        $messages[$lastIndex]['content'] = $this->groundedUserMessage($question, $passages);

        $reply = $this->llm->complete($messages, $preferredProviderKey);
        $reply['content'] = $this->normalizeCitations($reply['content']);
        $reply['query'] = $query;
        $reply['sources'] = $this->sources($passages, $reply['content']);
        $reply['context_chars'] = array_sum(array_map(function ($p) {
            return mb_strlen($p['content']);
        }, $passages));

        return $reply;
    }

    /**
     * Retrieval query for this turn. First turns are searched as-is; for
     * follow-ups ("what about the year before?") the LLM rewrites the
     * question into a standalone query using the recent history. Any
     * failure falls back to the raw question - rewriting is an
     * optimisation, never a point of failure.
     *
     * @param  string  $question
     * @param  array  $history
     * @return string
     */
    public function searchQuery(string $question, array $history): string
    {
        if (empty($history) || ! config('rag.rewrite_queries')) {
            return $question;
        }

        $transcript = '';
        foreach (array_slice($history, -6) as $message) {
            $speaker = $message['role'] === 'assistant' ? 'Assistant' : 'User';
            $transcript .= $speaker . ': ' . Str::limit(preg_replace('/\s+/u', ' ', $message['content']), 600) . "\n";
        }

        try {
            $reply = $this->llm->complete([
                [
                    'role' => 'system',
                    'content' => 'You rewrite the user\'s latest message into a single standalone search query for '
                        . 'looking up passages in a document. Resolve pronouns and references ("it", "that year", '
                        . '"the second one") using the conversation. Keep names, numbers, years and acronyms. If the '
                        . 'message is already standalone, return it unchanged. Output only the query - no quotes, '
                        . 'no explanation.',
                ],
                [
                    'role' => 'user',
                    'content' => "Conversation:\n{$transcript}\nLatest message: {$question}\n\nStandalone search query:",
                ],
            ]);
        } catch (LlmServiceException $e) {
            return $question;
        }

        $rewritten = trim(strtok(trim($reply['content']), "\n"), " \t\"'`");

        if ($rewritten === '' || mb_strlen($rewritten) > 500) {
            return $question;
        }

        Log::debug('RAG query rewritten', ['question' => $question, 'query' => $rewritten]);

        return $rewritten;
    }

    /**
     * The final user turn as the LLM sees it: numbered excerpts (each
     * labelled with document, section path and page), then the actual
     * question. The stored chat message itself is never modified.
     *
     * @param  string  $question
     * @param  array  $passages  ContextBuilder output
     * @return string
     */
    protected function groundedUserMessage(string $question, array $passages): string
    {
        $blocks = [];
        foreach ($passages as $i => $passage) {
            $blocks[] = sprintf("[%d] (%s)\n%s", $i + 1, $this->passageLabel($passage), $passage['content']);
        }

        return "Excerpts from the knowledge base:\n\n"
            . implode("\n\n", $blocks)
            . "\n\n---\nAnswer the question below using only the excerpts above, citing them like [1]. "
            . 'If they do not contain the answer, say: "' . config('rag.not_found_message') . "\"\n\n"
            . "Question: {$question}";
    }

    /**
     * "GEP June 2026 > Chapter 2: Regional Outlooks > South Asia > Outlook, p. 74 (PDF 96)".
     *
     * @param  array  $passage
     * @return string
     */
    protected function passageLabel(array $passage): string
    {
        $first = reset($passage['chunks']);
        $where = $first->document->title;
        if ($first->section_path) {
            $where .= ' > ' . $first->section_path;
        }

        return $where . ', ' . $this->passagePages($passage);
    }

    /**
     * @param  array  $passage
     * @return string
     */
    protected function passagePages(array $passage): string
    {
        $chunks = array_values($passage['chunks']);
        $first = $chunks[0];
        $last = end($chunks);

        // Reuse the chunk's own formatting on a span covering the passage.
        $span = $first->replicate();
        $span->page_start = $first->page_start;
        $span->page_end = $last->page_end;
        $startLabel = $first->page_label ? explode('-', $first->page_label)[0] : null;
        $endLabel = $last->page_label ? (explode('-', $last->page_label)[1] ?? explode('-', $last->page_label)[0]) : null;
        $span->page_label = $startLabel === null ? null : ($endLabel === null || $endLabel === $startLabel ? $startLabel : $startLabel . '-' . $endLabel);

        return $span->pageLabel();
    }

    /**
     * Some models (notably gpt-oss on Groq/OpenRouter) cite with their own
     * native markers - "【1】" or "【1†L3-L5】" - instead of the requested
     * "[1]". Rewrite those to the plain form so citation detection and the
     * UI's source mapping work regardless of which provider answered.
     *
     * @param  string  $answer
     * @return string
     */
    public function normalizeCitations(string $answer): string
    {
        return preg_replace('/\x{3010}\s*(\d+)[^\x{3011}]*\x{3011}/u', '[$1]', $answer);
    }

    /**
     * Source list in citation order. `cited` marks the excerpts the answer
     * actually referenced, so the UI can show only those.
     *
     * @param  array  $passages
     * @param  string  $answer
     * @return array<int, array>
     */
    protected function sources(array $passages, string $answer): array
    {
        // [1], [2][4] and [1, 3] styles.
        preg_match_all('/\[(\d+(?:\s*,\s*\d+)*)\]/', $answer, $matches);
        $cited = [];
        foreach ($matches[1] as $group) {
            foreach (explode(',', $group) as $number) {
                $cited[(int) trim($number)] = true;
            }
        }

        $sources = [];
        foreach ($passages as $i => $passage) {
            $chunks = array_values($passage['chunks']);
            $first = $chunks[0];
            $sources[] = [
                'index' => $i + 1,
                'document' => $first->document->title,
                'section' => $first->section_path,
                'kind' => $first->kind ?? 'text',
                'pages' => $this->passagePages($passage),
                'page_start' => (int) $first->page_start,
                'page_end' => (int) end($chunks)->page_end,
                'snippet' => Str::limit(preg_replace('/\s+/u', ' ', $passage['hit']->content), 280),
                'score' => round($passage['score'], 5),
                'cited' => isset($cited[$i + 1]),
            ];
        }

        return $sources;
    }
}
