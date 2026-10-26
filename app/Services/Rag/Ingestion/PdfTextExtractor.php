<?php

namespace App\Services\Rag\Ingestion;

use App\Exceptions\RagException;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Extracts text from a PDF (or plain-text file) one page at a time, so
 * every downstream chunk knows which page(s) it came from.
 */
class PdfTextExtractor
{
    /**
     * @param  string  $path
     * @return array<int, string>  1-based page number => raw page text
     *
     * @throws \App\Exceptions\RagException
     */
    public function extractPages(string $path): array
    {
        if (! is_file($path)) {
            throw new RagException("File not found: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'txt' || $extension === 'md') {
            // Plain text has no pages; honour form feeds if present.
            return $this->splitOnFormFeeds((string) file_get_contents($path));
        }

        if ($extension !== 'pdf') {
            throw new RagException("Unsupported file type \".{$extension}\" - expected .pdf, .txt or .md.");
        }

        $pages = $this->extractWithPdftotext($path);

        if ($pages === null) {
            $pages = $this->extractWithSmalot($path);
        }

        if (trim(implode('', $pages)) === '') {
            throw new RagException('No text could be extracted - the PDF may be scanned/image-only and need OCR first.');
        }

        return $pages;
    }

    /**
     * Pages in pdftotext's "-table" (or "-layout") mode, which keeps each
     * table row on one line with columns at fixed offsets - the reading
     * order mode used by extractPages() scatters table cells. Returns null
     * when pdftotext isn't available (structured ingestion then falls back
     * to treating tables as plain text).
     *
     * @param  string  $path
     * @param  string  $mode  "table" or "layout"
     * @return array<int, string>|null
     */
    public function extractPagesInMode(string $path, string $mode): ?array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }

        return $this->extractWithPdftotext($path, ['-' . $mode]);
    }

    /**
     * pdftotext (Poppler or Xpdf), in reading-order mode unless other
     * flags are given. Emits a form feed after every page, which is how
     * pages are told apart.
     *
     * @param  string  $path
     * @param  array<int, string>  $flags
     * @return array<int, string>|null  null if pdftotext is unavailable or failed
     */
    protected function extractWithPdftotext(string $path, array $flags = []): ?array
    {
        $binary = config('rag.pdftotext_binary');

        if (! $binary) {
            return null;
        }

        try {
            $process = new Process(array_merge([$binary], $flags, ['-enc', 'UTF-8', '-eol', 'unix', $path, '-']));
            $process->setTimeout(300);
            $process->run();
        } catch (Throwable $e) {
            Log::info('pdftotext unavailable, falling back to smalot/pdfparser', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::info('pdftotext failed, falling back to smalot/pdfparser', [
                'exit_code' => $process->getExitCode(),
                'error' => trim($process->getErrorOutput()),
            ]);

            return null;
        }

        return $this->splitOnFormFeeds($process->getOutput());
    }

    /**
     * @param  string  $path
     * @return array<int, string>
     */
    protected function extractWithSmalot(string $path): array
    {
        try {
            $document = (new PdfParser())->parseFile($path);
        } catch (Throwable $e) {
            throw new RagException('Unable to parse PDF: ' . $e->getMessage(), 0, $e);
        }

        $pages = [];
        foreach ($document->getPages() as $index => $page) {
            $pages[$index + 1] = (string) $page->getText();
        }

        return $pages;
    }

    /**
     * @param  string  $text
     * @return array<int, string>
     */
    protected function splitOnFormFeeds(string $text): array
    {
        $parts = explode("\f", $text);

        // pdftotext terminates the last page with a form feed too.
        if (count($parts) > 1 && trim(end($parts)) === '') {
            array_pop($parts);
        }

        $pages = [];
        foreach ($parts as $index => $part) {
            $pages[$index + 1] = $part;
        }

        return $pages;
    }
}
