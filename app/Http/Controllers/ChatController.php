<?php

namespace App\Http\Controllers;

use App\Exceptions\LlmServiceException;
use App\Http\Requests\SendMessageRequest;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Attachments\AttachmentService;
use App\Services\LLM\LlmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChatController extends Controller
{
    /**
     * @var \App\Services\LLM\LlmService
     */
    protected $llmService;

    /**
     * @var \App\Services\Attachments\AttachmentService
     */
    protected $attachmentService;

    public function __construct(LlmService $llmService, AttachmentService $attachmentService)
    {
        $this->llmService = $llmService;
        $this->attachmentService = $attachmentService;
    }

    /**
     * Show the chat shell with the sidebar list of conversations and no
     * active conversation selected.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        return view('chat.index', [
            'sessions' => $this->ownedSessions($request),
            'activeSession' => null,
            'messages' => collect(),
            'models' => $this->llmService->availableModels(),
        ]);
    }

    /**
     * Load an existing conversation and its message history.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\ChatSession  $chatSession
     * @return \Illuminate\View\View
     */
    public function show(Request $request, ChatSession $chatSession): View
    {
        $this->authorizeSession($request, $chatSession);

        return view('chat.index', [
            'sessions' => $this->ownedSessions($request),
            'activeSession' => $chatSession,
            'messages' => $chatSession->messages,
            'models' => $this->llmService->availableModels(),
        ]);
    }

    /**
     * Create a brand new, empty conversation belonging to the current
     * session owner. No messages are copied from any previous conversation.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $chatSession = ChatSession::create([
            'owner_key' => $this->ownerKey($request),
        ]);

        return response()->json([
            'uuid' => $chatSession->uuid,
            'title' => $chatSession->title,
            'url' => route('chat.show', $chatSession),
        ], 201);
    }

    /**
     * Send a message into a conversation and return the assistant's reply.
     *
     * Flow: validate -> store user message -> build context from this
     * conversation's own history only -> call the LLM -> store assistant
     * reply -> return both to the UI.
     *
     * @param  \App\Http\Requests\SendMessageRequest  $request
     * @param  \App\Models\ChatSession  $chatSession
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendMessage(SendMessageRequest $request, ChatSession $chatSession): JsonResponse
    {
        $this->authorizeSession($request, $chatSession);

        $validated = $request->validated();

        $userMessage = ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'user',
            'message' => $validated['message'],
        ]);

        if ($request->hasFile('attachment')) {
            $this->attachmentService->storeForMessage($userMessage, $request->file('attachment'));
            $userMessage->load('attachment');
        }

        if (empty($chatSession->title)) {
            $chatSession->title = Str::limit($userMessage->message, 40);
        }
        $chatSession->touch();
        $chatSession->save();

        try {
            $reply = $this->llmService->generateReply($chatSession, $validated['model'] ?? null);
        } catch (LlmServiceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        }

        $assistantMessage = ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'assistant',
            'message' => $reply['content'],
        ]);

        return response()->json([
            'user_message' => [
                'role' => $userMessage->role,
                'message' => $userMessage->message,
                'created_at' => $userMessage->created_at->toIso8601String(),
                'attachment' => $userMessage->attachment
                    ? ['filename' => $userMessage->attachment->original_filename]
                    : null,
            ],
            'assistant_message' => [
                'role' => $assistantMessage->role,
                'message' => $assistantMessage->message,
                'created_at' => $assistantMessage->created_at->toIso8601String(),
            ],
            'session' => [
                'uuid' => $chatSession->uuid,
                'title' => $chatSession->title,
            ],
            // Non-sensitive: just which provider/model answered, for an
            // optional "Powered by ..." UI hint. Never includes API keys.
            'provider' => [
                'key' => $reply['provider'],
                'label' => $reply['provider_label'],
            ],
        ]);
    }

    /**
     * Regenerate the assistant's last reply in a conversation.
     *
     * If the conversation's last message is an assistant reply, it's
     * discarded first so the LLM sees the exact same context that produced
     * it (ending on the preceding user message) and produces a fresh one.
     * If the last message is a user message (e.g. the previous attempt
     * failed before a reply was stored), nothing is discarded - the call
     * simply tries again with the same trailing context.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\ChatSession  $chatSession
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerate(Request $request, ChatSession $chatSession): JsonResponse
    {
        $this->authorizeSession($request, $chatSession);

        $validated = $request->validate([
            'model' => ['nullable', 'string', Rule::in(array_keys(config('llm.providers', [])))],
        ]);

        $lastMessage = $chatSession->messages()->orderByDesc('created_at')->orderByDesc('id')->first();

        if (! $lastMessage) {
            return response()->json([
                'message' => 'There is nothing to regenerate yet.',
            ], 422);
        }

        if ($lastMessage->role === 'assistant') {
            $lastMessage->delete();
        }

        try {
            $reply = $this->llmService->generateReply($chatSession, $validated['model'] ?? null);
        } catch (LlmServiceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 503);
        }

        $assistantMessage = ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'assistant',
            'message' => $reply['content'],
        ]);

        return response()->json([
            'assistant_message' => [
                'role' => $assistantMessage->role,
                'message' => $assistantMessage->message,
                'created_at' => $assistantMessage->created_at->toIso8601String(),
            ],
            'session' => [
                'uuid' => $chatSession->uuid,
                'title' => $chatSession->title,
            ],
            'provider' => [
                'key' => $reply['provider'],
                'label' => $reply['provider_label'],
            ],
        ]);
    }

    /**
     * Delete a conversation and all of its messages.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\ChatSession  $chatSession
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, ChatSession $chatSession): JsonResponse
    {
        $this->authorizeSession($request, $chatSession);

        // Must happen before delete(): the DB's ON DELETE CASCADE will wipe
        // the chat_attachments rows (and their disk_path values) as soon as
        // the session row goes, without ever firing an Eloquent event we
        // could hook into to clean up the underlying files.
        $this->attachmentService->deleteFilesForSession($chatSession);

        $chatSession->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * All conversations belonging to the current owner, most recently
     * updated first.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Support\Collection
     */
    protected function ownedSessions(Request $request)
    {
        return ChatSession::where('owner_key', $this->ownerKey($request))
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * The identifier used to scope conversations to their owner.
     *
     * There is no authentication system in this application, so Laravel's
     * own session ID (a long, random, server-verified string stored in a
     * secure/http-only cookie) is used as the ownership key instead. If
     * Laravel authentication is added later, this should simply become
     * `(string) $request->user()->id` so conversations are tied to the
     * authenticated account rather than the browser session.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function ownerKey(Request $request): string
    {
        if ($request->user()) {
            return 'user:' . $request->user()->getAuthIdentifier();
        }

        return 'session:' . $request->session()->getId();
    }

    /**
     * Abort with 403 if the given chat session does not belong to the
     * current owner, preventing access to other users'/sessions'
     * conversations.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\ChatSession  $chatSession
     * @return void
     */
    protected function authorizeSession(Request $request, ChatSession $chatSession): void
    {
        abort_unless($chatSession->owner_key === $this->ownerKey($request), 403);
    }
}
