<?php

namespace App\Console\Commands;

use App\Exceptions\LlmServiceException;
use App\Exceptions\RagException;
use App\Services\Rag\RagService;
use App\Services\Rag\Retrieval\HybridRetriever;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Runs a JSON question set through the full RAG pipeline and scores it:
 *
 *  - Retrieval: hit@k (did any retrieved chunk cover an expected page?)
 *    and MRR (how high was the first such chunk?).
 *  - Answer: every `must_include` fact group present in the answer.
 *  - Citations: answerable questions must cite at least one excerpt.
 *  - Refusal: `answerable: false` questions must be declined, not guessed.
 *
 *  - Section hit (optional `expected_section`): a retrieved chunk's section
 *    path contains the expected section, e.g. "South Asia > Outlook" -
 *    a stricter check than pages for long documents.
 *
 * Multi-turn cases (`turns`) replay earlier turns first, so follow-up
 * handling (query rewriting) is exercised end to end. `--document` limits
 * retrieval to given documents, e.g. to compare chunking strategies of the
 * same file.
 */
class RagEval extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rag:eval
        {file=resources/rag/eval/gpo_annual_report_2018.json : Question set (JSON)}
        {--only= : Comma-separated case ids to run}
        {--retrieval-only : Score retrieval only - no LLM calls}
        {--model= : Preferred provider key for generation}
        {--document=* : Restrict retrieval to these document ids}
        {--delay=0 : Seconds to wait between cases (free-tier rate limits)}
        {--retries=3 : Retries per case when every LLM provider is unavailable}
        {--retry-wait=30 : Seconds to wait before each retry}
        {--output= : Write the full per-case report (answers, sources) to this JSON file}';

    /**
     * @var string
     */
    protected $description = 'Evaluate RAG retrieval and answer quality against a question set';

    /**
     * @var array<int, int>|null
     */
    protected $documents;

    public function handle(RagService $rag, HybridRetriever $retriever): int
    {
        $path = $this->argument('file');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }

        $set = json_decode((string) @file_get_contents($path), true);
        if (! is_array($set) || empty($set['questions'])) {
            $this->error("Could not read a question set from {$path}");

            return 1;
        }

        $cases = $set['questions'];
        if ($only = $this->option('only')) {
            $ids = array_map('trim', explode(',', $only));
            $cases = array_values(array_filter($cases, function ($c) use ($ids) {
                return in_array($c['id'], $ids, true);
            }));
        }

        $retrievalOnly = (bool) $this->option('retrieval-only');
        $this->documents = $this->option('document') ? array_map('intval', $this->option('document')) : null;
        $reports = [];

        foreach ($cases as $i => $case) {
            if ($i > 0 && (int) $this->option('delay') > 0) {
                sleep((int) $this->option('delay'));
            }

            $this->output->write(sprintf('  %-4s %s ... ', $case['id'], Str::limit($this->finalQuestion($case), 70)));

            $started = microtime(true);
            for ($attempt = 0; ; $attempt++) {
                try {
                    $report = $retrievalOnly
                        ? $this->runRetrievalOnly($retriever, $case)
                        : $this->runCase($rag, $case);
                    break;
                } catch (LlmServiceException | RagException $e) {
                    // Every provider failing is almost always a free-tier
                    // rate limit; wait it out rather than scoring an
                    // infrastructure failure as a wrong answer.
                    if ($attempt < (int) $this->option('retries')) {
                        $this->output->write('<comment>(providers unavailable, retrying in ' . $this->option('retry-wait') . 's)</comment> ');
                        sleep((int) $this->option('retry-wait'));
                        continue;
                    }
                    $report = ['error' => $e->getMessage(), 'sources' => [], 'answer' => null];
                    break;
                }
            }
            $report['seconds'] = round(microtime(true) - $started, 1);

            $reports[] = $this->score($case, $report, $retrievalOnly);
            $this->line($this->verdictLabel(end($reports)));
        }

        $this->newLine();
        $this->renderTable($reports, $retrievalOnly);
        $this->renderSummary($reports, $retrievalOnly);

        if ($output = $this->option('output')) {
            file_put_contents($output, json_encode([
                'question_set' => $path,
                'run_at' => now()->toIso8601String(),
                'retrieval_only' => $retrievalOnly,
                'config' => [
                    'chunk_size' => config('rag.chunk_size'),
                    'chunk_overlap' => config('rag.chunk_overlap'),
                    'top_k' => config('rag.top_k'),
                    'candidates' => config('rag.candidates'),
                    'guaranteed_per_retriever' => config('rag.guaranteed_per_retriever'),
                    'embedding_model' => config('rag.embeddings.model'),
                    'embedding_dimensions' => config('rag.embeddings.dimensions'),
                    'rewrite_queries' => config('rag.rewrite_queries'),
                    'documents' => $this->documents,
                    'context_max_chars' => config('rag.context.max_chars'),
                    'neighbor_window' => config('rag.context.neighbor_window'),
                ],
                'summary' => $this->summary($reports, $retrievalOnly),
                'cases' => $reports,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->info("Full report written to {$output}");
        }

        return 0;
    }

    /**
     * @param  array  $case
     * @return string
     */
    protected function finalQuestion(array $case): string
    {
        return isset($case['turns']) ? end($case['turns']) : $case['question'];
    }

    /**
     * Replay all turns through the real pipeline; score the last.
     *
     * @param  \App\Services\Rag\RagService  $rag
     * @param  array  $case
     * @return array
     */
    protected function runCase(RagService $rag, array $case): array
    {
        $turns = $case['turns'] ?? [$case['question']];
        $messages = [['role' => 'system', 'content' => config('rag.system_prompt')]];

        foreach ($turns as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn];
            $result = $rag->answerMessages($messages, $this->option('model'), $this->documents);
            $messages[] = ['role' => 'assistant', 'content' => $result['content']];
        }

        return [
            'answer' => $result['content'],
            'query' => $result['query'],
            'provider' => $result['provider_label'],
            'sources' => $result['sources'],
            'context_chars' => $result['context_chars'] ?? null,
        ];
    }

    /**
     * @param  \App\Services\Rag\Retrieval\HybridRetriever  $retriever
     * @param  array  $case
     * @return array
     */
    protected function runRetrievalOnly(HybridRetriever $retriever, array $case): array
    {
        // No LLM means no follow-up rewriting: multi-turn cases search
        // all turns joined, the closest cheap approximation.
        $query = isset($case['turns']) ? implode(' ', $case['turns']) : $case['question'];

        $sources = [];
        foreach ($retriever->retrieve($query, null, $this->documents) as $i => $result) {
            $sources[] = [
                'index' => $i + 1,
                'section' => $result['chunk']->section_path,
                'pages' => $result['chunk']->pageLabel(),
                'page_start' => (int) $result['chunk']->page_start,
                'page_end' => (int) $result['chunk']->page_end,
                'cited' => false,
            ];
        }

        return ['answer' => null, 'query' => $query, 'provider' => null, 'sources' => $sources];
    }

    /**
     * @param  array  $case
     * @param  array  $report
     * @param  bool  $retrievalOnly
     * @return array
     */
    protected function score(array $case, array $report, bool $retrievalOnly): array
    {
        $answerable = $case['answerable'] ?? true;
        $scored = [
            'id' => $case['id'],
            'category' => $case['category'] ?? null,
            'question' => $this->finalQuestion($case),
            'answerable' => $answerable,
        ] + $report;

        if (isset($report['error'])) {
            $scored['pass'] = false;

            return $scored;
        }

        if ($answerable) {
            $expected = $case['expected_pages'] ?? [];
            $scored['hit_rank'] = null;
            foreach ($report['sources'] as $rank => $source) {
                if ($this->overlaps($source, $expected)) {
                    $scored['hit_rank'] = $rank + 1;
                    break;
                }
            }

            if (isset($case['expected_section'])) {
                $scored['section_hit'] = false;
                // "A|B" accepts either section.
                foreach ($report['sources'] as $source) {
                    foreach (explode('|', $case['expected_section']) as $expectedSection) {
                        if (stripos((string) ($source['section'] ?? ''), trim($expectedSection)) !== false) {
                            $scored['section_hit'] = true;
                            break 2;
                        }
                    }
                }
            }
        }

        if ($retrievalOnly) {
            $scored['pass'] = ! $answerable || $scored['hit_rank'] !== null;

            return $scored;
        }

        $answer = $this->normalize($report['answer']);

        if (! $answerable) {
            $scored['declined'] = $this->isDecline($answer);
            $scored['pass'] = $scored['declined'];

            return $scored;
        }

        $missing = [];
        foreach ($case['must_include'] ?? [] as $group) {
            $found = false;
            foreach ((array) $group as $alternative) {
                if (Str::contains($answer, $this->normalize($alternative))) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $missing[] = implode(' | ', (array) $group);
            }
        }

        $scored['facts_ok'] = empty($missing);
        $scored['missing'] = $missing;
        $scored['cited'] = (bool) preg_match('/\[\d+/', $report['answer']);
        $scored['cited_expected_page'] = false;
        foreach ($report['sources'] as $source) {
            if ($source['cited'] && $this->overlaps($source, $case['expected_pages'] ?? [])) {
                $scored['cited_expected_page'] = true;
                break;
            }
        }
        $scored['pass'] = $scored['facts_ok'] && $scored['cited'];

        return $scored;
    }

    /**
     * @param  array  $source
     * @param  array<int, int>  $pages
     * @return bool
     */
    protected function overlaps(array $source, array $pages): bool
    {
        foreach ($pages as $page) {
            if ($page >= $source['page_start'] && $page <= $source['page_end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, straight quotes, no thousands separators, single spaces.
     *
     * @param  string|null  $text
     * @return string
     */
    protected function normalize(?string $text): string
    {
        $text = mb_strtolower((string) $text);
        $text = str_replace(["\u{2019}", "\u{2018}", '**'], ["'", "'", ''], $text);
        $text = preg_replace('/(?<=\d),(?=\d{3})/u', '', $text);

        return preg_replace('/\s+/u', ' ', $text);
    }

    /**
     * @param  string  $normalizedAnswer
     * @return bool
     */
    protected function isDecline(string $normalizedAnswer): bool
    {
        return (bool) preg_match(
            "/(couldn't|could not|cannot|can't|unable to) find|(do|does) not (contain|include|mention|provide|specify)|not (mentioned|provided|included|specified|available) in/",
            $normalizedAnswer
        );
    }

    /**
     * @param  array  $report
     * @return string
     */
    protected function verdictLabel(array $report): string
    {
        if (isset($report['error'])) {
            return '<error>ERROR</error> ' . Str::limit($report['error'], 80);
        }

        return ($report['pass'] ? '<info>PASS</info>' : '<comment>FAIL</comment>') . " ({$report['seconds']}s)";
    }

    /**
     * @param  array  $reports
     * @param  bool  $retrievalOnly
     * @return void
     */
    protected function renderTable(array $reports, bool $retrievalOnly): void
    {
        $headers = $retrievalOnly
            ? ['ID', 'Category', 'Hit rank', 'Pass']
            : ['ID', 'Category', 'Hit rank', 'Facts', 'Cited', 'Cites exp. page', 'Provider', 'Pass'];

        $yesNo = function ($value) {
            return $value === null ? '-' : ($value ? 'yes' : 'NO');
        };

        $rows = [];
        foreach ($reports as $r) {
            $hit = $r['answerable'] ? ($r['hit_rank'] ?? 'miss') : 'n/a';
            if ($retrievalOnly) {
                $rows[] = [$r['id'], $r['category'], $hit, $yesNo($r['pass'])];
                continue;
            }
            if (! $r['answerable']) {
                $rows[] = [$r['id'], $r['category'], 'n/a', 'declined: ' . $yesNo($r['declined'] ?? null), '-', '-', $r['provider'] ?? '-', $yesNo($r['pass'])];
                continue;
            }
            $rows[] = [
                $r['id'],
                $r['category'],
                $hit,
                isset($r['error']) ? 'ERROR' : $yesNo($r['facts_ok'] ?? null),
                $yesNo($r['cited'] ?? null),
                $yesNo($r['cited_expected_page'] ?? null),
                $r['provider'] ?? '-',
                $yesNo($r['pass']),
            ];
        }

        $this->table($headers, $rows);

        foreach ($reports as $r) {
            if (! empty($r['missing'])) {
                $this->line("  <comment>{$r['id']}</comment> missing: " . implode('; ', $r['missing']));
                $this->line('       answer: ' . Str::limit(preg_replace('/\s+/u', ' ', (string) $r['answer']), 220));
            }
        }
    }

    /**
     * @param  array  $reports
     * @param  bool  $retrievalOnly
     * @return array
     */
    protected function summary(array $reports, bool $retrievalOnly): array
    {
        // Cases that errored (every provider down / rate-limited) say
        // nothing about pipeline quality, so metrics are computed over
        // completed cases only; the error count is reported alongside.
        $completed = array_values(array_filter($reports, function ($r) {
            return ! isset($r['error']);
        }));
        $answerable = array_values(array_filter($completed, function ($r) {
            return $r['answerable'];
        }));
        $unanswerable = array_values(array_filter($completed, function ($r) {
            return ! $r['answerable'];
        }));

        $count = function (array $items, callable $predicate) {
            return count(array_filter($items, $predicate));
        };
        $pct = function ($n, $d) {
            return $d > 0 ? round(100 * $n / $d, 1) : null;
        };

        $hits = $count($answerable, function ($r) {
            return ($r['hit_rank'] ?? null) !== null;
        });
        $mrr = count($answerable) > 0
            ? round(array_sum(array_map(function ($r) {
                return ($r['hit_rank'] ?? null) ? 1 / $r['hit_rank'] : 0;
            }, $answerable)) / count($answerable), 3)
            : null;

        $summary = [
            'cases' => count($reports),
            'errors' => $count($reports, function ($r) {
                return isset($r['error']);
            }),
            'retrieval_hit_rate_pct' => $pct($hits, count($answerable)),
            'retrieval_mrr' => $mrr,
        ];

        $withSection = array_values(array_filter($answerable, function ($r) {
            return array_key_exists('section_hit', $r);
        }));
        if (! empty($withSection)) {
            $summary['section_hit_rate_pct'] = $pct($count($withSection, function ($r) {
                return $r['section_hit'];
            }), count($withSection));
        }

        if (! $retrievalOnly) {
            $summary['answer_accuracy_pct'] = $pct($count($answerable, function ($r) {
                return ! empty($r['facts_ok']);
            }), count($answerable));
            $summary['citation_rate_pct'] = $pct($count($answerable, function ($r) {
                return ! empty($r['cited']);
            }), count($answerable));
            $summary['cites_expected_page_pct'] = $pct($count($answerable, function ($r) {
                return ! empty($r['cited_expected_page']);
            }), count($answerable));
            $summary['refusal_accuracy_pct'] = $pct($count($unanswerable, function ($r) {
                return ! empty($r['declined']);
            }), count($unanswerable));
            $contextSizes = array_filter(array_column($completed, 'context_chars'));
            $summary['avg_context_chars'] = empty($contextSizes) ? null : (int) round(array_sum($contextSizes) / count($contextSizes));
        }

        $summary['overall_pass_pct'] = $pct($count($completed, function ($r) {
            return $r['pass'];
        }), count($completed));

        return $summary;
    }

    /**
     * @param  array  $reports
     * @param  bool  $retrievalOnly
     * @return void
     */
    protected function renderSummary(array $reports, bool $retrievalOnly): void
    {
        $labels = [
            'cases' => 'Cases',
            'errors' => 'Errors (excluded from metrics below)',
            'retrieval_hit_rate_pct' => 'Retrieval hit@' . config('rag.top_k') . ' (%)',
            'retrieval_mrr' => 'Retrieval MRR',
            'section_hit_rate_pct' => 'Expected section retrieved (%)',
            'avg_context_chars' => 'Avg context sent to LLM (chars)',
            'answer_accuracy_pct' => 'Answer accuracy - all facts present (%)',
            'citation_rate_pct' => 'Answers with citations (%)',
            'cites_expected_page_pct' => 'Answers citing an expected page (%)',
            'refusal_accuracy_pct' => 'Unanswerable correctly declined (%)',
            'overall_pass_pct' => 'Overall pass (%)',
        ];

        $rows = [];
        foreach ($this->summary($reports, $retrievalOnly) as $key => $value) {
            $rows[] = [$labels[$key] ?? $key, $value === null ? '-' : $value];
        }

        $this->table(['Metric', 'Value'], $rows);
    }
}
