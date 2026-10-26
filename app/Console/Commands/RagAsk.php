<?php

namespace App\Console\Commands;

use App\Exceptions\LlmServiceException;
use App\Exceptions\RagException;
use App\Services\Rag\RagService;
use Illuminate\Console\Command;

class RagAsk extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rag:ask {question : Question to answer from the knowledge base} {--model= : Preferred provider key (gemini, groq, openrouter)} {--document=* : Restrict to these document ids}';

    /**
     * @var string
     */
    protected $description = 'Answer a question from the ingested documents, with citations';

    public function handle(RagService $rag): int
    {
        try {
            $documents = $this->option('document') ? array_map('intval', $this->option('document')) : null;
            $answer = $rag->answerQuestion($this->argument('question'), $this->option('model'), $documents);
        } catch (LlmServiceException | RagException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->line($answer['content']);
        $this->newLine();
        $this->line('<comment>Answered by:</comment> ' . ($answer['provider_label'] ?: '-') . ' ' . ($answer['model'] ? "({$answer['model']})" : ''));
        $this->line('<comment>Sources:</comment>');
        foreach ($answer['sources'] as $source) {
            $this->line(sprintf(
                '  %s[%d] %s, %s',
                $source['cited'] ? '*' : ' ',
                $source['index'],
                $source['document'],
                $source['pages'] . (! empty($source['section']) ? ' - ' . $source['section'] : '')
            ));
        }

        return 0;
    }
}
