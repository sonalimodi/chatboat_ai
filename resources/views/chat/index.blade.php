<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Chatbot</title>
    <link rel="stylesheet" href="{{ asset('css/chat.css') }}">
    <script>
        // Applied before first paint to avoid a flash of the wrong theme.
        (function () {
            try {
                var saved = window.localStorage.getItem('chatbot_theme');
                if (saved === 'light' || saved === 'dark') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) { /* localStorage unavailable (private mode, etc.) - default theme applies */ }
        }());
    </script>
</head>
<body>
    <div class="app"
         data-new-chat-url="{{ route('chat.new') }}"
         data-chat-index-url="{{ route('chat.index') }}"
         data-active-uuid="{{ optional($activeSession)->uuid }}"
         data-max-attachment-kb="{{ config('attachments.max_size_kb') }}">

        <div class="sidebar-backdrop" id="sidebar-backdrop"></div>

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <button id="new-chat-btn" class="new-chat-btn" type="button">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    New Chat
                </button>
                <button id="sidebar-close-btn" class="icon-btn sidebar-close-btn" type="button" aria-label="Close sidebar">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="session-list" id="session-list">
                @forelse ($sessions as $session)
                    <div class="session-item {{ optional($activeSession)->uuid === $session->uuid ? 'active' : '' }}"
                         data-uuid="{{ $session->uuid }}">
                        <a href="{{ route('chat.show', $session) }}" class="session-link">
                            <svg class="session-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <span class="session-title">{{ $session->title ?: 'New conversation' }}</span>
                        </a>
                        <button type="button" class="icon-btn delete-session-btn" data-uuid="{{ $session->uuid }}" title="Delete conversation" aria-label="Delete conversation">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                @empty
                    <p class="no-sessions">No conversations yet.</p>
                @endforelse
            </div>
        </aside>

        <div class="main-column">
            <header class="app-header">
                <button id="sidebar-toggle-btn" class="icon-btn sidebar-toggle-btn" type="button" aria-label="Toggle sidebar">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>

                <div class="app-brand">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span class="app-brand-name">AI Chatbot</span>
                </div>

                <div class="header-title-wrap">
                    <h1 class="chat-title">{{ optional($activeSession)->title ?: 'New conversation' }}</h1>
                </div>

                <div class="header-actions">
                    <div class="model-selector" id="model-selector">
                        <button type="button" class="model-selector-btn" id="model-selector-btn" aria-haspopup="listbox" aria-expanded="false">
                            <span id="model-selector-label">Loading models&hellip;</span>
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="model-dropdown" id="model-dropdown" role="listbox" hidden></div>
                    </div>

                    <button type="button" class="icon-btn theme-toggle-btn" id="theme-toggle-btn" aria-label="Toggle theme" title="Toggle theme">
                        <svg class="icon-sun" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/><line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/><line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/></svg>
                        <svg class="icon-moon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>

                    <button type="button" class="icon-btn header-new-chat-btn" id="header-new-chat-btn" aria-label="New chat" title="New chat">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </button>
                </div>
            </header>

            <main class="chat-area">
                <div class="messages" id="messages">
                    @forelse ($messages as $message)
                        <div class="message message-{{ $message->role }}">
                            <div class="message-avatar" aria-hidden="true">
                                @if ($message->role === 'user')
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                @else
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><line x1="12" y1="7" x2="12" y2="11"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>
                                @endif
                            </div>
                            <div class="message-content">
                                <div class="message-meta">
                                    <span class="message-role-name">{{ $message->role === 'user' ? 'You' : 'Assistant' }}</span>
                                    <span class="message-time">{{ $message->created_at->format('g:i A') }}</span>
                                </div>
                                @if ($message->attachment)
                                    <div class="attachment-chip">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <span>{{ $message->attachment->original_filename }}</span>
                                    </div>
                                @endif
                                <div class="message-bubble">{{ $message->message }}</div>
                                @if ($message->role === 'assistant')
                                    <div class="message-actions">
                                        <button type="button" class="msg-action-btn copy-btn" title="Copy" aria-label="Copy message">
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        </button>
                                        <button type="button" class="msg-action-btn regenerate-btn" title="Regenerate response" aria-label="Regenerate response" hidden>
                                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="empty-state" id="empty-state">
                            <div class="empty-state-inner">
                                <h2>How can I help you today?</h2>
                                <div class="suggestion-grid">
                                    <button type="button" class="suggestion-card" data-prompt="Write a function that reverses a string, with an explanation.">
                                        <span class="suggestion-icon">💻</span>
                                        <span class="suggestion-text">Write code</span>
                                    </button>
                                    <button type="button" class="suggestion-card" data-prompt="Help me debug this: my code throws an error and I don't know why. What questions should I answer to figure it out?">
                                        <span class="suggestion-icon">🐛</span>
                                        <span class="suggestion-text">Debug an issue</span>
                                    </button>
                                    <button type="button" class="suggestion-card" data-prompt="Help me write a short, friendly email announcing a new feature to my team.">
                                        <span class="suggestion-icon">✍️</span>
                                        <span class="suggestion-text">Write something</span>
                                    </button>
                                    <button type="button" class="suggestion-card" data-prompt="Brainstorm 5 creative ideas for a weekend side project.">
                                        <span class="suggestion-icon">💡</span>
                                        <span class="suggestion-text">Brainstorm ideas</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforelse
                </div>

                <div class="loading-row" id="loading-row" hidden>
                    <div class="message-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><line x1="12" y1="7" x2="12" y2="11"/></svg>
                    </div>
                    <div class="typing-dots"><span></span><span></span><span></span></div>
                </div>

                <div class="error-banner" id="error-banner" hidden>
                    <span id="error-banner-text"></span>
                    <button type="button" class="retry-btn" id="retry-btn" hidden>Retry</button>
                </div>

                <form id="chat-form" class="chat-form" autocomplete="off" enctype="multipart/form-data">
                    <div class="pending-attachment" id="pending-attachment" hidden>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span id="pending-attachment-name"></span>
                        <button type="button" id="remove-attachment-btn" class="icon-btn" aria-label="Remove attachment" title="Remove attachment">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="chat-input-row">
                        <input type="file" id="attachment-input" accept=".pdf,.txt,application/pdf,text/plain" hidden>
                        <button type="button" id="attach-btn" class="icon-btn attach-btn" title="Attach a PDF or text file" aria-label="Attach a file">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        </button>
                        <textarea id="message-input" name="message" placeholder="Message AI Chatbot..." maxlength="4000" rows="1" required></textarea>
                        <button type="submit" id="send-btn" aria-label="Send message">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        </button>
                    </div>
                </form>
            </main>
        </div>
    </div>

    <script type="application/json" id="available-models">{!! json_encode($models, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.1.5/dist/purify.min.js"></script>
    <script src="{{ asset('js/chat.js') }}"></script>
</body>
</html>
