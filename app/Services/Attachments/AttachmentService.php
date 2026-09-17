<?php

namespace App\Services\Attachments;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Stores an uploaded attachment (PDF or TXT) and extracts its text once, up
 * front, so LlmService can cheaply replay that text into future requests
 * without ever needing to re-read or re-parse the original file.
 */
class AttachmentService
{
    /**
     * Store the given file and attach it to a chat message.
     *
     * @param  \App\Models\ChatMessage  $message
     * @param  \Illuminate\Http\UploadedFile  $file
     * @return \App\Models\ChatAttachment
     */
    public function storeForMessage(ChatMessage $message, UploadedFile $file): ChatAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $disk = config('attachments.disk');

        $path = $file->storeAs('attachments', (string) Str::uuid() . '.' . $extension, $disk);

        return ChatAttachment::create([
            'chat_message_id' => $message->id,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'disk_path' => $path,
            'size_bytes' => $file->getSize(),
            'extracted_text' => $this->extractText($file, $extension),
        ]);
    }

    /**
     * Delete every attachment's underlying file for a chat session, before
     * the session itself is deleted.
     *
     * The `chat_attachments` and `chat_messages` rows are cleaned up by the
     * database's own ON DELETE CASCADE once the session row is deleted -
     * but a DB-level cascade never fires Eloquent model events, so it can't
     * be relied on to clean up files on disk. This has to run first, while
     * the disk paths are still known.
     *
     * @param  \App\Models\ChatSession  $chatSession
     * @return void
     */
    public function deleteFilesForSession(ChatSession $chatSession): void
    {
        $disk = Storage::disk(config('attachments.disk'));

        $paths = ChatAttachment::whereIn('chat_message_id', $chatSession->messages()->pluck('id'))
            ->pluck('disk_path');

        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }

    /**
     * @param  \Illuminate\Http\UploadedFile  $file
     * @param  string  $extension
     * @return string|null
     */
    protected function extractText(UploadedFile $file, string $extension): ?string
    {
        try {
            if ($extension === 'txt') {
                $text = file_get_contents($file->getRealPath());
            } elseif ($extension === 'pdf') {
                $parser = new PdfParser();
                $text = $parser->parseFile($file->getRealPath())->getText();
            } else {
                return null;
            }
        } catch (Throwable $e) {
            Log::warning('Failed to extract attachment text', [
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $max = config('attachments.max_extract_chars');

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max)
                . "\n\n[Content truncated - the file is longer than the excerpt above.]";
        }

        return $text;
    }
}
