# Laravel AI Chatbot (Multi-Provider LLM with Automatic Fallback)

A ChatGPT-style, context-aware chatbot built on Laravel 8, MySQL, and Blade.
It talks to LLM providers over HTTP (no local model to install) and
automatically falls back to the next configured provider if one fails —
so a user still gets a response as long as at least one provider is up.

**Providers supported out of the box:** [Google Gemini](https://aistudio.google.com/apikey) (primary),
[Groq](https://console.groq.com/keys) (fallback #1, free tier), [OpenRouter](https://openrouter.ai/keys) (fallback #2, free tier).

---

## 1. Project Overview

The app lets a visitor:

- Start any number of independent conversations ("chats").
- Send messages and get replies from an LLM, with automatic provider
  fallback if the primary one is down, rate-limited, or misconfigured.
- Have follow-up questions understood correctly ("what's the second one?")
  because prior turns of the *same* conversation are sent back as context —
  to whichever provider ends up answering.
- Change topic mid-conversation without breaking the chat.
- Start a brand new conversation that never sees another conversation's
  history.
- Delete a conversation.

Everything (chat sessions and every individual message) is stored in MySQL,
not in a single JSON blob, so the history is queryable and manageable.

> **Note on session-based context:** some chatbot specs ask for conversation
> history to be kept in the Laravel session (`session(['history' => ...])`).
> This app instead keeps history in the `chat_messages` table, scoped by
> `chat_session_id` — the same requirement (context carried across turns of
> one conversation only), but persisted, queryable, and safe across page
> reloads, multiple tabs, and multiple concurrent conversations, none of
> which a single session array handles well. See [Architecture](#8-architecture).

---

## 2. Requirements

- PHP >= 7.3 (this project targets Laravel 8 / PHP 7.3, matching the
  existing project — no framework or PHP upgrade was made or is required),
  with the `fileinfo` extension enabled (default in most PHP builds,
  including Laragon's) - needed to validate uploaded attachments' real
  content type
- **Composer 2** (not 1 - see the note at the end of [§17](#17-file-attachments-pdftxt)
  if `composer require` fails with "Could not find package" for something
  that clearly exists; Laragon ships Composer 2 at `laragon/bin/composer/composer`)
- MySQL 5.7+/8.0 (Laragon ships this)
- An API key for at least one provider (Gemini, Groq, and/or OpenRouter —
  see [LLM Provider Setup](#5-llm-provider-setup))
- A modern browser (no build step / Node.js is required to run the app —
  the frontend is plain Blade + vanilla JS + CSS)

---

## 3. Installation

```bash
composer install
```

If starting fresh (no `.env` yet):

```bash
copy .env.example .env      # Windows
php artisan key:generate
```

---

## 4. Database Setup

1. **Create the database** (via Laragon's HeidiSQL/phpMyAdmin, or the MySQL CLI):

   ```sql
   CREATE DATABASE chatbot_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Point `.env` at it** — `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, etc. (see [Environment Configuration](#6-environment-configuration)). The repo's `.env.example` already assumes `chatbot_ai` as the database name and `root`/no password, matching a default Laragon MySQL install.

3. **Run the migrations** — all of them are already included in `database/migrations/`, nothing extra to generate:

   ```bash
   php artisan migrate
   ```

   | Migration | Creates |
   |---|---|
   | `2014_10_12_000000_create_users_table` | `users` (Laravel default, unused by the chat feature itself) |
   | `2014_10_12_100000_create_password_resets_table` | `password_resets` (Laravel default) |
   | `2019_08_19_000000_create_failed_jobs_table` | `failed_jobs` (Laravel default) |
   | `2026_09_16_000001_create_chat_sessions_table` | `chat_sessions` — one row per conversation |
   | `2026_09_16_000002_create_chat_messages_table` | `chat_messages` — one row per message, FK to `chat_sessions` |
   | `2026_09_16_000003_create_chat_attachments_table` | `chat_attachments` — one row per uploaded PDF/TXT, FK to `chat_messages` |

   Verify with `php artisan migrate:status` — all six should show `Yes` under **Ran?**. See [Database Schema](#database-schema) in the Architecture section for each table's columns.

4. **(Optional) roll back / redo cleanly** during development: `php artisan migrate:fresh` drops every table and re-runs all migrations from scratch — useful if you want a clean slate, but it deletes all existing conversations.

---

## 5. LLM Provider Setup

You only need **one** provider enabled with a valid key for the app to
work. Configuring two or three gives you automatic fallback.

### Free tier vs. paid — what's actually true right now

| Provider | Free tier? | Notes |
|---|---|---|
| **Gemini** | Yes, free tier via Google AI Studio | Rate/usage limits apply; some newer models may be paid-only — check current limits at [ai.google.dev/pricing](https://ai.google.dev/pricing) before relying on a specific model. |
| **Groq** | Yes, free forever tier, no credit card | ~30 requests/min, ~14,400 requests/day on free models as of this writing (Sept 2026) — verify current numbers at [console.groq.com/docs/rate-limits](https://console.groq.com/docs/rate-limits). |
| **OpenRouter** | Yes, for models tagged `:free` only | Free models are capped (~20 req/min, ~50 req/day; a one-time $10 credit purchase raises the daily cap). Omitting `:free` from a model ID routes to the **paid** version and bills your account. Current free models: [openrouter.ai/models?max_price=0](https://openrouter.ai/models?max_price=0). |

**Do not assume any of these numbers stay accurate** — providers change
pricing and free-tier limits often (we confirmed this ourselves mid-build:
`gemini-2.0-flash` had already been retired in favor of `gemini-3.6-flash`
by the time this was tested). Always check the linked docs before treating
a specific model as free.

### Getting each key

1. **Gemini** — go to [aistudio.google.com/apikey](https://aistudio.google.com/apikey), sign in with a Google account, click **Create API key**. Paste it into `GEMINI_API_KEY`.
2. **Groq** — go to [console.groq.com/keys](https://console.groq.com/keys), sign up (no credit card), create a key. Paste it into `GROQ_API_KEY` and set `GROQ_ENABLED=true`.
3. **OpenRouter** — go to [openrouter.ai/keys](https://openrouter.ai/keys), sign up, create a key. Paste it into `OPENROUTER_API_KEY`, set `OPENROUTER_ENABLED=true`, and make sure `OPENROUTER_MODEL` ends in `:free` unless you intend to pay.

**Never commit real keys.** `.env` is already git-ignored; `.env.example` ships with all keys blank.

---

## 6. Environment Configuration

```dotenv
# LLM provider fallback order - tried strictly left to right
LLM_PROVIDERS=gemini,groq,openrouter
LLM_TIMEOUT=60
LLM_MAX_HISTORY_MESSAGES=20

# Primary - Google Gemini
GEMINI_ENABLED=true
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.6-flash
GEMINI_URL=https://generativelanguage.googleapis.com/v1beta

# Fallback #1 - Groq (free tier)
GROQ_ENABLED=false
GROQ_API_KEY=
GROQ_MODEL=openai/gpt-oss-20b
GROQ_URL=https://api.groq.com/openai/v1

# Fallback #2 - OpenRouter (free tier)
OPENROUTER_ENABLED=false
OPENROUTER_API_KEY=
OPENROUTER_MODEL=openai/gpt-oss-20b:free
OPENROUTER_URL=https://openrouter.ai/api/v1
```

| Key | Purpose |
|---|---|
| `LLM_PROVIDERS` | Comma-separated provider keys, tried **strictly left to right**. A provider absent from this list is never used even if it has a key configured. |
| `{PROVIDER}_ENABLED` | Per-provider on/off switch, independent of `LLM_PROVIDERS` ordering. Both must be true (enabled + listed in the order) for a provider to be attempted. |
| `{PROVIDER}_API_KEY` | Secret key. Read only via `config('llm.providers.*.api_key')` — never hard-coded, never sent to the browser, never logged. |
| `{PROVIDER}_MODEL` | Model ID as the provider expects it. |
| `{PROVIDER}_URL` | Provider's API base URL — override only if a provider changes its endpoint. |
| `LLM_TIMEOUT` | Seconds Laravel waits for **each** provider attempt before treating it as failed and moving to the next one. |
| `LLM_MAX_HISTORY_MESSAGES` | Max prior messages (both roles) replayed as context, to whichever provider answers. |

All of this is read via [config/llm.php](config/llm.php) — application code never calls `env()` directly outside that file.

To pick up `.env` changes: `php artisan config:clear` (or just restart `php artisan serve`) if config caching is in use.

---

## 7. Running the Application

```bash
php artisan serve
```

Visit <http://127.0.0.1:8000/chat> (or your Laragon vhost, e.g. `http://chatbot_ai.test/chat` — the app redirects `/` to `/chat`).

---

## 8. Architecture

```
resources/views/chat/index.blade.php   Chat UI (sidebar + message pane)
public/css/chat.css                    Styling
public/js/chat.js                      Fetch-based chat client (no framework)

routes/web.php                         Route definitions

app/Http/Controllers/ChatController.php   HTTP layer only: validate, persist,
                                           call the service, return JSON/views
app/Http/Requests/SendMessageRequest.php  Validates the "message" field

app/Services/LLM/
    LlmService.php                     Orchestrator: builds conversation
                                        context once, tries each configured
                                        provider in order, logs failures,
                                        returns the first success or throws
                                        LlmServiceException if all fail.
    Contracts/LlmProviderInterface.php Common chat(array $messages): array
                                        contract every provider implements.
    Providers/GeminiProvider.php       Gemini generateContent wire format
                                        (system prompt -> systemInstruction,
                                        assistant -> "model" role).
    Providers/AbstractChatCompletionsProvider.php
                                        Shared OpenAI-compatible
                                        /chat/completions request logic.
    Providers/GroqProvider.php         extends the above; Groq specifics.
    Providers/OpenRouterProvider.php   extends the above; OpenRouter specifics.

app/Exceptions/LlmServiceException.php Thrown only once every configured
                                        provider has failed; message is
                                        always safe to show the user.

app/Services/Attachments/AttachmentService.php
                                        Stores an uploaded PDF/TXT, extracts
                                        its text once at upload time, and
                                        cleans up files on disk when a
                                        conversation is deleted.

app/Models/ChatSession.php             hasMany ChatMessage, UUID public key
app/Models/ChatMessage.php             belongsTo ChatSession, hasOne ChatAttachment
app/Models/ChatAttachment.php          belongsTo ChatMessage

config/llm.php                         Provider configs, fallback order,
                                        timeout, history limit, system
                                        prompt - all environment-driven
config/attachments.php                 Max upload size, max extracted
                                        characters, storage disk

database/migrations/..._create_chat_sessions_table.php
database/migrations/..._create_chat_messages_table.php
database/migrations/..._create_chat_attachments_table.php
```

Nothing outside `app/Services/LLM/Providers/*` knows a specific provider's
request/response shape, auth header, or error format — the controller and
`LlmService` only ever deal with the neutral message list in and a
normalized `{success, content, provider, model}` / `{success:false, error,
error_type, status}` array out.

### Database Schema

**chat_sessions**

| Column     | Type              | Notes                                   |
|------------|-------------------|------------------------------------------|
| id         | bigint, PK         | Internal primary key (used for FKs)      |
| uuid       | uuid, unique       | Public identifier used in URLs/routing   |
| owner_key  | string, indexed    | Scopes a conversation to its owner       |
| title      | string, nullable   | Auto-filled from the first user message  |
| timestamps | -                  | created_at / updated_at                  |

**chat_messages**

| Column           | Type                          | Notes                          |
|------------------|-------------------------------|----------------------------------|
| id               | bigint, PK                     |                                  |
| chat_session_id  | bigint, FK -> chat_sessions.id | Cascades on delete               |
| role             | enum(system,user,assistant)    |                                  |
| message          | text                            |                                  |
| timestamps       | -                               | created_at used for ordering     |

Each message is its own row — the conversation is never collapsed into a
single JSON column — so it can be queried, paginated, or pruned per-message.
This is also what makes the fallback provider see identical context to the
primary provider: both read from the exact same rows.

**chat_attachments**

| Column           | Type                            | Notes                                    |
|------------------|----------------------------------|--------------------------------------------|
| id               | bigint, PK                       |                                            |
| chat_message_id  | bigint, FK -> chat_messages.id   | Cascades on delete (DB rows only - see below) |
| original_filename| string                           | For display; never used to build a path   |
| mime_type        | string                           | Client-reported, informational only        |
| disk_path        | string                           | Random UUID filename on the `local` disk   |
| size_bytes       | unsigned int                     |                                            |
| extracted_text   | longtext, nullable               | Extracted once at upload; truncated to `ATTACHMENT_MAX_EXTRACT_CHARS` |
| timestamps       | -                                 |                                            |

One attachment per message, uploaded alongside the user's text in the same
`POST /messages` request (as `multipart/form-data`). The file itself is
never re-parsed after upload — `extracted_text` is what gets replayed into
future LLM requests.

### Routes

| Method | URI                          | Name                 | Purpose                                   |
|--------|-------------------------------|-----------------------|--------------------------------------------|
| GET    | `/chat`                       | `chat.index`          | Chat shell + list of the caller's sessions |
| GET    | `/chat/{uuid}`                | `chat.show`           | Load one conversation and its messages     |
| POST   | `/chat/new`                   | `chat.new`            | Create a brand-new, empty conversation     |
| POST   | `/chat/{uuid}/messages`       | `chat.messages.store` | Send a message, get the assistant's reply  |
| DELETE | `/chat/{uuid}`                | `chat.destroy`        | Delete a conversation (cascades messages)  |

### Session Ownership (no authentication)

This app doesn't implement a login system, so conversations are scoped to
Laravel's own **session ID** (`$request->session()->getId()`) — a long,
random identifier stored in a signed, `HttpOnly` cookie. Every
conversation-touching action checks the conversation's stored `owner_key`
against the current session's key and aborts with `403` otherwise.

**If Laravel authentication is added later**, change
`ChatController::ownerKey()` to `'user:' . $request->user()->id` — that's
the only method that needs to change.

---

## 9. Conversation Flow & Provider Fallback

1. User sends a message.
2. `ChatController::sendMessage()` validates it, stores it as a `user`
   `chat_messages` row, and calls `LlmService::generateReply($chatSession)`.
3. `LlmService` builds **one** neutral message list — the system prompt
   plus the last `LLM_MAX_HISTORY_MESSAGES` messages of *this* conversation,
   oldest first, as `{role, content}` pairs — before touching any provider.
4. It walks `LLM_PROVIDERS` in order. For each enabled + configured
   provider:
   - Calls `$provider->chat($messages)`.
   - If it returns `success: true`, that reply is used immediately — no
     further providers are tried.
   - If it returns `success: false` (timeout, rate limit, invalid key,
     unavailable model, any other HTTP failure, or a connection error),
     the failure is logged (`Log::warning('LLM provider failed: <key>', [...])`,
     with provider, model, HTTP status, error type and timestamp — **never**
     the API key or raw Authorization header) and the loop moves to the
     next provider in the list.
5. If every configured provider fails (or none are configured/enabled at
   all), `LlmService` throws `LlmServiceException` with a single fixed,
   user-safe message. `ChatController` catches it and returns HTTP 503:
   > "Sorry, the AI service is temporarily unavailable. Please try again later."
6. On success, the assistant reply is stored as another `chat_messages`
   row, and the response also includes which provider answered
   (`{"provider": {"key": "groq", "label": "Groq"}}`) purely for the
   optional, non-sensitive "Assistant · Groq" label shown in the UI next to
   that reply — no credentials are ever included.

```
Gemini
   ↓ failure (logged)
Groq
   ↓ failure (logged)
OpenRouter
   ↓ failure (logged)
"Sorry, the AI service is temporarily unavailable. Please try again later."
```

Because every provider is handed the exact same message list built once in
step 3, a conversation that started with Gemini and falls back to Groq
mid-conversation keeps full context — the fallback provider isn't starting
from scratch.

### Context Window Management

Simple, configurable message-count cutoff (`LLM_MAX_HISTORY_MESSAGES`,
default 20) — not full token counting. For production, track actual token
counts and/or summarize older turns instead of dropping them outright once
a conversation exceeds the window.

---

## 10. API Endpoints

### `POST /chat/{uuid}/messages`
Body (JSON, when there's no attachment): `{ "message": "...", "model": "gemini" }`
Body (`multipart/form-data`, when there is): fields `message`, `model` (optional), `attachment` (optional file).

`model` is optional; when given (and a `key()` of an enabled, configured
provider), that provider is tried first, with the rest of `LLM_PROVIDERS`
still available as fallback if it fails. Omit it to just use the default
fallback order. `attachment` must be a `.pdf` or `.txt` file (validated by
actual detected content type, not just the extension) no larger than
`ATTACHMENT_MAX_SIZE_KB`.

```json
// 200 - success (from whichever provider answered)
{
  "user_message": { "role": "user", "message": "...", "created_at": "...", "attachment": { "filename": "notes.txt" } },
  "assistant_message": { "role": "assistant", "message": "...", "created_at": "..." },
  "session": { "uuid": "...", "title": "..." },
  "provider": { "key": "gemini", "label": "Gemini" }
}
```

```json
// 503 - every configured provider failed
{ "message": "Sorry, the AI service is temporarily unavailable. Please try again later." }
```

```json
// 422 - e.g. a disallowed file type or a file over the size limit
{ "message": "The given data was invalid.", "errors": { "attachment": ["The attachment must be a file of type: pdf, txt."] } }
```

`403` if the conversation isn't owned by the current session.

### `POST /chat/{uuid}/regenerate`
Body: `{ "model": "groq" }` (optional). Discards the conversation's last
assistant reply (if any) and generates a fresh one from the same trailing
context — used by the message list's regenerate button and by the error
banner's "Retry" button. If the LLM call fails, the original reply (if one
existed) is restored rather than left deleted, so a failed regenerate never
loses data. Response shape matches `POST /messages` minus `user_message`.

The other endpoints (`GET /chat`, `GET /chat/{uuid}`, `POST /chat/new`, `DELETE /chat/{uuid}`) are unchanged by this update.

---

## 11. Example Configurations

**Gemini only (no fallback):**
```dotenv
LLM_PROVIDERS=gemini
GEMINI_ENABLED=true
GEMINI_API_KEY=your-real-key
```

**Gemini primary, Groq fallback:**
```dotenv
LLM_PROVIDERS=gemini,groq
GEMINI_ENABLED=true
GEMINI_API_KEY=your-gemini-key
GROQ_ENABLED=true
GROQ_API_KEY=your-groq-key
```

**All three, Gemini → Groq → OpenRouter:**
```dotenv
LLM_PROVIDERS=gemini,groq,openrouter
GEMINI_ENABLED=true
GEMINI_API_KEY=your-gemini-key
GROQ_ENABLED=true
GROQ_API_KEY=your-groq-key
OPENROUTER_ENABLED=true
OPENROUTER_API_KEY=your-openrouter-key
OPENROUTER_MODEL=openai/gpt-oss-20b:free
```

---

## 12. Test Cases

These were used to validate the implementation (some via direct `curl`
against the app, some by temporarily overriding `config('llm.*')` in
`php artisan tinker` to simulate failures without needing real invalid keys
for every provider):

| # | Scenario | How to trigger | Expected result |
|---|---|---|---|
| 1 | **Gemini success** | Valid `GEMINI_API_KEY`, `LLM_PROVIDERS=gemini`, send a message | 200 response, `provider.key = "gemini"`, reply stored |
| 2 | **Gemini timeout** | Set `LLM_TIMEOUT=1` with a real key (or point `GEMINI_URL` at an unroutable host) | Provider attempt fails fast with a connection/timeout error, logged as `error_type: connection_error`; falls back or returns 503 if it's the only provider |
| 3 | **Gemini rate limit (429)** | Simulate by exhausting real quota, or unit-test `GeminiProvider::errorType(429)` directly | Logged as `error_type: rate_limited`; falls back to the next provider |
| 4 | **Gemini invalid API key** | Set `GEMINI_API_KEY=invalid-key-test` | Gemini returns HTTP 400 with `reason: API_KEY_INVALID`; logged as `error_type: invalid_api_key` (confirmed live during development — see below) |
| 5 | **Fallback to Groq** | Invalid Gemini key + valid Groq key, `LLM_PROVIDERS=gemini,groq` | Gemini attempt logged as failed; Groq attempt succeeds; response `provider.key = "groq"` |
| 6 | **Fallback to OpenRouter** | Invalid Gemini + invalid/absent Groq + valid OpenRouter key, `LLM_PROVIDERS=gemini,groq,openrouter` | Gemini and Groq both logged as failed; OpenRouter succeeds; response `provider.key = "openrouter"` |
| 7 | **All providers unavailable** | No providers enabled, or all keys invalid | Every attempt logged; `POST /chat/{uuid}/messages` returns 503 with the fixed friendly message; no stack trace or provider detail reaches the client |
| 8 | **Conversation context maintained** | Send "My name is Rahul." then "What is my name?" in the same chat | Second reply correctly answers "Rahul" — confirmed live during development (see transcript below) |

**Live confirmation of #4, #5, and #8** (captured during development; keys
redacted):

```
$ curl -X POST http://127.0.0.1:8000/chat/{uuid}/messages -d '{"message":"My name is Rahul."}'
{"assistant_message":{"message":"Hello Rahul! Nice to meet you. How can I help you today?"}, "provider":{"key":"gemini"}}

$ curl -X POST http://127.0.0.1:8000/chat/{uuid}/messages -d '{"message":"What is my name?"}'
{"assistant_message":{"message":"Your name is Rahul."}, "provider":{"key":"gemini"}}
```

```
# tinker, simulating an invalid key with no fallback configured:
Caught: Sorry, the AI service is temporarily unavailable. Please try again later.

# storage/logs/laravel.log:
[...] local.WARNING: LLM provider failed: gemini {"provider":"gemini","model":"gemini-3.6-flash","status":400,"error_type":"invalid_api_key", ...}
```

**Real fallback-to-Groq, with `LLM_PROVIDERS=gemini,groq,openrouter` and a
genuinely invalid `GEMINI_API_KEY`** (the request went through the actual
HTTP app, not a tinker simulation):

```
$ curl -X POST http://127.0.0.1:8000/chat/{uuid}/messages \
    -d '{"message":"Which provider are you? Just answer in one short sentence."}'
{"assistant_message":{"message":"I'm an AI language model created by OpenAI."}, "provider":{"key":"groq","label":"Groq"}}

# storage/logs/laravel.log (same request):
[...] local.ERROR: Gemini returned an error response {"status":400,"body":"... API key not valid ..."}
[...] local.WARNING: LLM provider failed: gemini {"provider":"gemini", "error_type":"invalid_api_key", ...}
```

Gemini's key was restored immediately after this test; Groq stays
configured and enabled as a live, working fallback going forward — not just
a synthetic one. (The reply mentions OpenAI because `openai/gpt-oss-20b`
is OpenAI's own open-weight model, served by Groq's infrastructure — the
model naming itself, not a bug.)

Test case 6 (fallback to OpenRouter) requires that key specifically and
wasn't exercised with a real key in this session; the fallback *mechanism*
is identical to the Groq case already proven above — the loop simply
continues to the next configured provider.

---

## 13. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| "Sorry, the AI service is temporarily unavailable..." | Every enabled provider failed. Check `storage/logs/laravel.log` for `LLM provider failed: <key>` entries — each names the provider, HTTP status, and error type. |
| A specific provider always fails | Check its `*_ENABLED=true`, `*_API_KEY` is set and valid, and it's present in `LLM_PROVIDERS`. |
| `SQLSTATE[HY000] [1049] Unknown database` | The database in `DB_DATABASE` doesn't exist yet — create it, then re-run `php artisan migrate`. |
| 403 when opening a chat link | The conversation belongs to a different browser session — this is the ownership check working as intended. |
| CSRF `419` errors on POST/DELETE | Session cookie expired or was blocked — refresh `/chat` to get a fresh token. |
| Loading indicator never disappears | Hard-refresh (Ctrl+F5) to bust the browser's cache of `public/css/chat.css` — an earlier version had a CSS specificity bug where the indicator ignored its `hidden` attribute. |

Technical errors (connection failures, HTTP error bodies, unexpected
responses) are written to `storage/logs/laravel.log` — the UI only ever
shows the fixed, safe message above. API keys and Authorization headers are
never written to logs.

---

## 14. Future Improvements

- Token-based context window management with summarization of older turns.
- Streaming responses so replies appear incrementally.
- Real user accounts instead of session-based ownership.
- Automated PHPUnit feature tests for the chat flow, ownership checks, and
  provider fallback.
- Circuit-breaking a provider that's failed repeatedly (skip it for N
  minutes) instead of retrying it on every single message.
- Surfacing which providers are currently configured/healthy in an admin
  view, rather than only in the logs.
- Multiple attachments per message (currently one), and support for more
  file types (e.g. `.docx`, `.csv`) via additional extractors.
- Antivirus/content scanning for uploaded attachments before extraction,
  if this were ever exposed beyond a trusted/internal audience.

---

## 15. Markdown Rendering & XSS Safety

Assistant replies are rendered as formatted markdown (bold, lists, code
blocks, links, tables) instead of plain text, since LLMs consistently
return markdown-formatted output.

**Rendering happens entirely client-side** in `public/js/chat.js`, using
two CDN-loaded libraries declared in `resources/views/chat/index.blade.php`:

1. **[marked](https://cdn.jsdelivr.net/npm/marked@11.1.1)** parses the raw
   markdown text into HTML.
2. **[DOMPurify](https://cdn.jsdelivr.net/npm/dompurify@3.1.5)** sanitizes
   that HTML before it's inserted into the page, stripping `<script>` tags,
   event handler attributes (`onerror`, `onclick`, ...), and `javascript:`
   URLs. A small `DOMPurify.addHook(...)` forces every surviving `<a>` to
   `target="_blank" rel="noopener noreferrer"`.

This is the same rendering pipeline both for a freshly-received reply and
for messages loaded from the database on page reload — `renderAssistantBubble()`
is called in both places, and `appendMessage()` never uses `innerHTML`
directly with unsanitized text.

**User messages are never markdown-rendered** — they're always inserted via
`textContent`, so a user typing literal `<script>` or markdown syntax has it
displayed as inert text, not executed or formatted.

**Graceful degradation:** if the CDN is unreachable (offline dev, corporate
firewall, ad blocker), `renderAssistantBubble()` falls back to the same
plain-`textContent` rendering used before this feature — the chat keeps
working, replies just show literal markdown syntax instead of formatted
text, and no error is thrown.

Both the rendering and the sanitization were verified with a real headless
browser (Playwright) during development:
- A live message asking for bold text, a bullet list, and inline code
  round-tripped through Gemini and rendered as real `<strong>`, `<ul><li>`,
  and `<code>` elements.
- A payload containing `<script>alert(2)</script>`, `<img onerror=...>`,
  and a `javascript:` link — fed directly into the same `marked.parse()` →
  `DOMPurify.sanitize()` pipeline the app uses — came out with the script
  tag removed entirely, `onerror` stripped, and the `javascript:` URL
  removed from the link's `href`.
- With the CDN blocked outright, sending a message still completed
  normally and displayed safe plain text instead of crashing.

---

## 16. Modern UI/UX

The interface was redesigned into a ChatGPT/Claude/Gemini-style layout —
still plain Blade + vanilla JS + CSS, no framework or build step.

**Layout:** a collapsible sidebar (conversation history, active-item
highlight, delete), and a header with the app brand, a conversation title,
a model selector, a theme toggle, and a "new chat" icon button. On screens
≤ 880px the sidebar becomes an off-canvas drawer opened by a hamburger
button, closed by its own × button, an outside click on the dimmed
backdrop, or the Escape key.

**Model selector is real, not cosmetic:** its options come from
`LlmService::availableModels()` (label, description, badge, underlying
model — no keys) embedded server-side as JSON; picking one is sent as
`model` on every `/messages` and `/regenerate` call, and
`LlmService::generateReply()` tries that provider first before falling
back through the rest of `LLM_PROVIDERS`. The choice persists in
`localStorage` across reloads. This was verified live: selecting "Groq" in
the UI and sending a message came back tagged `provider: groq`, confirmed
by both a Playwright test and a screenshot showing the "Assistant · Groq"
badge.

**Message list:** user messages are right-aligned colored bubbles;
assistant messages are left-aligned with an avatar, a provider badge, and a
timestamp (`g:i A` server-side for page loads, `toLocaleTimeString` for
live ones). Every assistant message has a **copy** button (copies the
rendered plain text via `navigator.clipboard`) and the *most recent*
assistant message only gets a **regenerate** button — `refreshRegenerateButtons()`
re-hides it from every earlier message whenever a new one arrives, so
there's never ambiguity about which reply "regenerate" applies to.

**Empty state:** a "How can I help you today?" welcome screen with four
clickable suggestion cards (Write code / Debug an issue / Write something /
Brainstorm ideas); clicking one fills the input and submits immediately.

**Errors:** the error banner gained a **Retry** button (shown whenever the
failure happened after a conversation already existed) that calls
`/regenerate` — this doubles as "retry a failed send", since a failed send
leaves the conversation's last message as an unanswered user turn, which
`regenerate()` handles as its non-deletion branch.

**Theme:** dark by default; the toggle flips a `data-theme` attribute on
`<html>` and persists the choice in `localStorage`. An inline script in
`<head>` (before the stylesheet/body render) applies the saved theme
immediately, so there's no flash of the wrong theme on reload.

All of the above (model selection actually affecting the request, theme
persistence across reload, the mobile drawer opening/closing, copy, and
regenerate replacing rather than duplicating the last reply) was verified
with real headless-browser tests during development, not just by reading
the code — see the test transcript summary in this project's development
history if you'd like the exact assertions.

---

## 17. File Attachments (PDF/TXT)

A user can attach one PDF or TXT file to a message (paperclip icon next to
the input). The file is never sent to the LLM as binary/base64 data —
instead, its **text is extracted once at upload time** and stored, then
replayed as extra context on that turn (and any later turn where that
message is still within `LLM_MAX_HISTORY_MESSAGES`) so follow-up questions
about the file keep working.

This design was a deliberate choice: Gemini can accept files directly, but
Groq's and OpenRouter's text-only chat-completions endpoints can't — since
this app has to work with whichever provider ends up answering (including
mid-conversation fallback), extracting to plain text is the only approach
that's portable across all of them.

**Flow:** select a file → client validates extension/size and shows a
preview chip → on send, the request becomes `multipart/form-data` instead
of JSON → `SendMessageRequest` validates the real file content (`mimes:pdf,txt`,
not just the extension) → `AttachmentService` stores it on the `local`
disk under a random UUID filename and extracts its text (`smalot/pdfparser`
for PDF, a plain read for TXT) → `LlmService::buildConversationPayload()`
appends `[Attached file: name]\n<extracted text>` to that message's content
before sending to a provider. The extracted text is capped at
`ATTACHMENT_MAX_EXTRACT_CHARS` (truncated with a note if longer) so one
large file can't blow out the context window.

**Storage & cleanup:** attachments live on a private disk (`storage/app/attachments`,
not `public/`) — there's no download route, since the only consumer of the
file is the extraction step. Deleting a conversation deletes the physical
files too: this needed an explicit fix, since the `chat_attachments` table's
`ON DELETE CASCADE` only removes database rows at the database level and
never fires an Eloquent model event, so `ChatController::destroy()` calls
`AttachmentService::deleteFilesForSession()` *before* deleting the session,
while the disk paths are still known.

**Verified live**, with a real headless browser and hand-built PDF/TXT
fixtures (not just unit-level checks):
- Uploading a `.txt` file containing a made-up "secret code" and asking
  the LLM for it → the reply correctly quoted the code from the file.
- A follow-up question in the same conversation ("who is the project
  lead mentioned in that file?") was answered correctly with no
  re-attachment — proving the extracted text replays via normal history,
  not just on the turn it was uploaded.
- The same test repeated with a hand-built PDF (via `smalot/pdfparser`)
  and a different embedded secret code — extraction and the LLM's answer
  both correct.
- Client-side: selecting a disallowed extension shows an error before any
  upload happens; the remove (×) button clears a pending attachment.
- Server-side (bypassing the browser entirely): a file with real PNG bytes
  named `.pdf` was rejected with `422` (content-type detection, not
  extension trust); a 6MB file was rejected against the 5MB default limit.
- Deleting a conversation that had an attachment actually removed the file
  from `storage/app/attachments`, confirmed by listing the directory
  before and after.

**Note on this project's environment:** adding this feature required a new
Composer package (`smalot/pdfparser`), which surfaced that this project's
system-wide Composer (v1.10) could no longer resolve *any* package —
Packagist [shut down Composer 1 support in September 2025](https://blog.packagist.com/shutting-down-packagist-org-support-for-composer-1-x/).
Laragon's bundled Composer (`C:\laragon\bin\composer\composer`, v2.4.1) was
used instead and works fine. If you hit "Could not find package" errors for
packages that clearly exist, this is almost certainly why — point your
terminal/IDE at a Composer 2 binary.

---

## 18. RAG Knowledge Base (GPO Annual Report)

The chat can answer questions **grounded in ingested documents**, starting
with the **GPO Annual Report 2018** (U.S. Government Publishing Office, fiscal year
ending September 30, 2018; 82 pages: a 30-page narrative report plus the audited
consolidated financial statements). This is the **baseline** pipeline: plain
sentence-aligned chunks, no structure recovery (see §19 for the structured one).
Answers cite their sources as `[n]`, and the UI lists the cited excerpts with
document and page numbers. Questions the report can't answer are declined
instead of guessed.

### Quick start

```bash
php artisan migrate

# Copy the report PDF (or any PDF/TXT/MD) into storage/app/rag/ first
php artisan rag:ingest storage/app/rag/gpo_annual_report_2018.pdf --title="GPO Annual Report 2018" --strategy=flat
php artisan rag:ask "How many passports did GPO produce in FY 2018?"
php artisan rag:eval --document=<id>     # validate against the question set (id from rag:list)
```

In the chat UI, the **"Answer from <document titles>"** toggle above the
input appears once a document has been ingested. It is on by default, and the
setting is remembered per browser.

### Pipeline

```
PDF ──► extract per page ──► clean ──► chunk ──► embed ──► MySQL (rag_documents, rag_chunks)
                                                              │
question ─► (follow-up? rewrite to standalone query) ─► hybrid retrieve (dense + BM25, RRF)
                                                              │
                     top-k numbered excerpts + question ─► LLM (Gemini → Groq → OpenRouter)
                                                              │
                                     answer with [n] citations + source list (stored on the message)
```

| Stage | Implementation | Why |
|---|---|---|
| **Extraction** | `PdfTextExtractor`: Poppler `pdftotext` per page (ships with Git for Windows), falling back to `smalot/pdfparser` | `pdftotext` takes 0.4 s on this report; smalot took more than 5 minutes, and pdftotext handles multi-column layout better. Per-page text is what lets chunks cite pages. |
| **Cleaning** | `TextCleaner` | Strips running headers/footers: short, non-sentence lines at a page's top/bottom that repeat on ≥5% of pages, e.g. `U. S. Government Publishing Office \| Financial Year In Review FY 2018`. Also strips bare page numbers and dot leaders, fixes ligatures, rejoins lines wrapped mid-sentence (including the blank-line-separated lines that narrow columns produce) and repairs hyphenation. Unit notes like `(Dollars in Thousands)` are deliberately kept. |
| **Chunking** | `TextChunker`: sentence-aligned, ~1,200 chars (~300 tokens), ~200 chars overlap, page range per chunk | Annual reports are dense with distinct figures, and smaller focused chunks retrieve them precisely. Sentences are never cut, abbreviations (`U.S.`, `P.L.`) don't split, and a paragraph that runs onto the next page is stitched back together. The 2018 report gives 159 chunks. |
| **Embeddings** | `GeminiEmbeddingProvider`: `gemini-embedding-001`, 768 dims, `RETRIEVAL_DOCUMENT` / `RETRIEVAL_QUERY` task types, L2-normalised | Reuses the existing Gemini key. Truncated Gemini vectors aren't pre-normalised, so they're normalised here. Batched, with 429/5xx backoff that honours the API's `retryDelay`. Behind `EmbeddingProviderInterface`, so it's swappable. |
| **Storage** | MySQL; vectors as base64 packed float32 (~4 KB each) | A few hundred chunks don't need a vector DB: brute-force cosine in PHP takes milliseconds. Each document records its embedding model/dims, and mismatched spaces are never compared. |
| **Retrieval** | `HybridRetriever`: dense cosine + `Bm25Index`, top 20 each, weighted Reciprocal Rank Fusion (k=60), top-2 of each retriever guaranteed, top 8 returned | Dense handles paraphrase; BM25 catches exact figures, years and acronyms (`TDR`, `FECA`). The guarantee fixes a real failure seen in testing: "How many people work at GPO?" was dense rank #1 for the `employed 1,662 staff` chunk but had no keyword overlap, so plain RRF ranked it below chunks both retrievers rated moderately. |
| **Query rewriting** | `RagService::searchQuery()` | Follow-ups ("And how many since 2005?") are condensed by the LLM into a standalone query before retrieval. First turns skip this call. Any failure falls back to the raw question. |
| **Generation** | `RagService`: system prompt + history + numbered excerpts injected into the final user turn, via `LlmService::complete()` | Same provider fallback as normal chat. The prompt requires excerpt-only answers, `[n]` citations, exact figures with units and fiscal year, and a fixed "couldn't find" reply. Native `【1】` citation markers from gpt-oss models are normalised to `[1]`. |

### Commands

| Command | Purpose |
|---|---|
| `rag:ingest {path} [--title=] [--force] [--dry-run] [--dump=file]` | Ingest a file (idempotent by SHA-256). `--dry-run --dump=chunks.txt` previews the chunks without calling the API. |
| `rag:list [--delete=id]` | List or remove documents. |
| `rag:search "query" [--k=]` | Show retrieved chunks with fused, dense and BM25 ranks. No LLM call. |
| `rag:ask "question" [--model=]` | Full answer with cited sources. |
| `rag:eval [file] [--retrieval-only] [--only=q01,f02] [--delay=] [--output=report.json]` | Score the question set. |

Re-run `rag:ingest ... --force` after changing chunking or embedding settings.

### Validation

`resources/rag/eval/gpo_annual_report_2018.json` contains 31 questions written
against facts verified in the report (the official sample questions have not
been provided yet). It covers headline facts, the historical tables, the
financial statements and their notes, the audit, programs and history, plus a
multi-hop question, 2 multi-turn follow-ups and 3 unanswerable questions
(an out-of-scope year, an absent fact, off-topic). Each
answerable case lists the PDF pages that hold the answer and the facts the
answer must contain (with accepted alternatives, e.g. `15.2 million` or
`15,901,600`).

`rag:eval` measures:

- **Retrieval:** hit@k (a retrieved chunk covers an expected page) and MRR.
- **Answer accuracy:** every required fact is present.
- **Citation rate:** answers cite excerpts, and an expected page specifically.
- **Refusal accuracy:** unanswerable questions are declined.

Cases where every LLM provider was rate-limited are retried, then reported as
errors and excluded from the quality metrics.

**Results (2026-10-21, GPO 2018, 31 cases):** generation went through the
normal fallback chain: 15 answers came from Gemini and 16 from Groq
`openai/gpt-oss-20b` when Gemini hit per-minute limits.

| Metric | Result |
|---|---|
| Retrieval hit@8 | **100%** (28/28 answerable) |
| Retrieval MRR | **0.905** |
| Answer accuracy (all required facts present) | **96.4%** (27/28) |
| Answers with citations / citing an expected page | **100% / 100%** |
| Unanswerable questions correctly declined | **100%** (3/3) |
| Avg context sent to the LLM | ~8,800 chars |
| Overall | **30/31 pass** |

The one failure is q18 ("largest customer"). The right page (PDF p. 81,
note 15 "Major Customers") was retrieved at rank 1, but the PDF stores that
table's rows out of order: customer names and billing figures come out as
separate runs. The model paired the 2017 figure (37.9%) with "U.S.
Congress" instead of State Department at 33.3%. This is the table-extraction
limitation listed below, not a retrieval miss.

Retrieval ablation on the same set (`rag:eval --retrieval-only` with env
overrides `RAG_DENSE_WEIGHT` / `RAG_LEXICAL_WEIGHT` /
`RAG_GUARANTEED_PER_RETRIEVER`):

| Configuration | hit@8 | MRR |
|---|---|---|
| Dense only | 100% | 0.917 |
| BM25 only | 96.4% | 0.842 |
| Hybrid RRF, no guarantee | 100% | 0.905 |
| **Hybrid RRF + top-2 guarantee (default)** | **100%** | **0.905** |

On this set, dense retrieval alone ranks slightly better (0.917 vs 0.905),
because the questions mostly use the report's own vocabulary and BM25 now
and then pulls a neighbouring page above the expected one. Every hit still
lands in the top 8, so answers are unaffected. Hybrid stays the default:
it costs nothing here and it protects exact-figure, acronym and
element-reference queries ("Table 2.10", "SDT"), where dense embeddings are
weakest.

Free-tier rate limits are the practical constraint. Use `--delay=6` (or
more) for a full run, and `--only=...` to re-run individual cases.

### Known limitations / next steps

- **Charts and some financial tables** extract as runs of numbers detached
  from their labels, e.g. the "GPO By the Numbers" page and the balance-sheet
  statements on pp. 58–59. Prose summaries of the same figures exist and are
  what retrieval finds. Table-aware extraction (or a vision model over page
  images) would make the raw statements queryable too.
- **Scanned PDFs** have no text layer and need OCR before ingestion.
- **Retrieval is brute force.** It's fine for one report (milliseconds), but
  thousands of documents would need a vector index (pgvector, Qdrant, etc.)
  behind `HybridRetriever`'s dense step.
- **No reranker.** A cross-encoder rerank of the top 20 is the natural next
  quality step if the question set grows harder.
- **The eval set is self-authored.** Swap in or extend
  `resources/rag/eval/*.json` with the official sample questions when they're
  available. The format is documented in the file's `notes` field.

---

## 19. Long, Structured Documents (World Bank Global Economic Prospects)

The 200-page [World Bank *Global Economic Prospects*, June 2026](https://thedocs.worldbank.org/en/doc/2b672b3b0415d6b66c45b66579db4ef5-0050012026/original/GEP-Jun-2026.pdf)
is ingested with a **structured (section-aware) strategy**. The flat
strategy from §18 still exists for short documents and for comparison.

```bash
curl -L -o storage/app/rag/gep_june_2026.pdf \
  https://thedocs.worldbank.org/en/doc/2b672b3b0415d6b66c45b66579db4ef5-0050012026/original/GEP-Jun-2026.pdf
php artisan migrate
php artisan rag:ingest storage/app/rag/gep_june_2026.pdf --title="Global Economic Prospects June 2026"   # structured is the default
php artisan rag:ingest storage/app/rag/gep_june_2026.pdf --dry-run --dump=gep.txt                     # inspect the section tree + chunks
php artisan rag:eval resources/rag/eval/gep_june_2026.json --delay=6
```

### What breaks with flat chunking on a long report

| Problem in this report | Effect on flat RAG | Structured fix |
|---|---|---|
| Six regional sections share identical headings ("Recent developments", "Outlook"), and their text says "the region" rather than naming it | A chunk about South Asia's outlook is indistinguishable from Sub-Saharan Africa's | Every chunk carries its **section breadcrumb** (`Chapter 2: Regional Outlooks > South Asia > Outlook`), which is embedded, keyword-indexed and shown to the LLM |
| 25 forecast tables (country × year), some switching from calendar to fiscal years mid-table | Reading-order extraction scrambles cells; numbers lose their row/column | Tables are re-extracted in `pdftotext -table` mode and **every row is serialised against the header in force**, e.g. `India \| 2026/27f: 6.6 \| … \| [Percentage-point differences from January 2026 projections] 2026/27f: 0.1`; row groups repeat the caption |
| 53 figures whose axis ticks dump hundreds of number fragments into the text | Noise chunks that match numeric queries | Figures keep the caption, key-finding sentence, panel titles and notes; chart residue is dropped |
| Boxes (multi-page, with their own subsections) interleaved with main text | Box and chapter text mixed in one chunk | Boxes become their own subtree (`… > Box 3.1: The costs of hidden debt > Mozambique`), with subheadings detected inside them |
| The "Th" and "fi" ligatures are missing from some fonts ("e conflict", "ailand", "nancial") | Broken words, so "Thailand" can't be found | `GlyphRepair` restores them, with sentence-start rules so wrapped text isn't touched |
| ~175k tokens of text | Can't send the whole report; small chunks lose context, large chunks lose precision | Small chunks (1,000 chars) for retrieval, then **neighbour expansion** within the section and passage merging under a character budget |

### Pipeline

```
PDF ─► reading-order text ─┬─► glyph repair ─► strip running headers ─┐
    ├► "-table" mode text ─┘                                         ├─► StructureParser ─► blocks
    └► "-layout" mode (TOC pages) ─► TocParser ──────────────────────┘     (section / text / table / figure / close)
                                                                             │
                     StructuredChunker: section tree (rag_sections) + chunks with breadcrumbs (rag_chunks)
                                                                             │
query ─► rewrite follow-ups ─► hybrid retrieval (dense + BM25 over breadcrumb+text, + element-reference routing, RRF)
                                                                             │
             ContextBuilder: widen hits with same-section neighbours, merge adjacent, de-duplicate overlap, budget
                                                                             │
                    numbered passages labelled "document > section path, p. 74 (PDF 96)" ─► LLM ─► cited answer
```

**How the structure is recovered** (`app/Services/Rag/Structure/`):

- **Chapters and parts:** divider pages (`CHAPTER 2 / REGIONAL OUTLOOKS`, `STATISTICAL APPENDIX`).
- **Sections and subsections:** the printed table of contents is parsed from
  layout-mode text (`TocParser`). Nesting comes from TOC indentation, which
  shifts between odd and even TOC pages, so it is resolved per page against
  known anchors (chapter titles, front matter). Each entry is then found in
  the body **in TOC order and near its promised page**. This is what tells
  apart the six identical "Outlook" headings. The report matches 87 of 93
  entries. One TOC entry ("Regional developments") is printed differently in
  the body ("Recent developments") and is skipped.
- **Boxes, figures, tables, annexes:** found from their captions. Boxes span
  pages while each page repeats `BOX n (continued)`.
- **Printed page numbers:** the PDF page minus a constant offset found from
  the page-number lines in headers/footers (22 for this report). Citations
  show both forms, e.g. "p. 74 (PDF 96)".
- **Statistical appendix pages** (uncaptioned full-page tables) are detected
  by their share of numeric rows plus a year/quarter header row.

**Retrieval and context management:**

- **Element references.** A question naming "Box 3.1", "Table 2.2",
  "Figure 1.4", "Annex 4.2" or "Chapter 3" adds a third ranking to the fusion:
  that element's chunks (weight `RAG_REFERENCE_WEIGHT`, default 2.0).
- **Document filter.** `--document=ID` on `rag:search`, `rag:ask` and
  `rag:eval` is used to compare strategies on the same file.
- **Neighbour expansion and budget.** `RAG_NEIGHBOR_WINDOW` (default 1) and
  `RAG_CONTEXT_MAX_CHARS` (default 12,000, about 3k tokens) keep prompts
  inside free-tier per-minute token limits.
- **Embedding cache.** Embeddings are cached by text hash
  (`rag_embedding_cache`) and written batch by batch. Re-ingesting after a
  parser change only re-embeds the chunks whose text changed, and an
  interrupted run resumes where it stopped.

### Validation

`resources/rag/eval/gep_june_2026.json` holds 26 questions written from the
PDF, since the official sample questions have not been provided yet. They
cover:

- headline, regional and commodity figures, plus chapter findings and policy;
- boxes, tables and the statistical appendix;
- questions that name an element ("Table 2.2", "Box 3.1");
- two conversational follow-ups;
- four questions the report cannot answer.

Each case also has an `expected_section`, so the eval checks that the right
part of the report was retrieved, not just the right page.

```bash
php artisan rag:eval resources/rag/eval/gep_june_2026.json --document=5 --retrieval-only
php artisan rag:eval resources/rag/eval/gep_june_2026.json --document=5 --delay=6 --output=storage/app/rag/eval_gep_full.json
```

**Results (2026-10-23, structured strategy: 203 sections, 793 chunks):**
Gemini generated 22 answers and Groq 4, through the normal fallback chain.
In the full run, f02 failed with "all providers rate-limited" (an error, not
a wrong answer); it passed when re-run on its own with `--only=f02`.

| Metric | Result |
|---|---|
| Retrieval hit@8 | **100%** (22/22 answerable) |
| Expected section retrieved | **100%** |
| Retrieval MRR | **0.82** |
| Answer accuracy (all required facts present) | **100%** (22/22) |
| Answers with citations / citing an expected page | **100% / 100%** |
| Unanswerable questions correctly declined | **100%** (4/4) |
| Avg context sent to the LLM | ~11,000 chars (of the 12,000 budget) |
| Overall | **26/26 pass** |

The MRR is lower than on the GPO set (0.82 vs 0.905), but the misses are
near-misses. In all six cases ranked 2nd or 3rd, the chunk above the expected
page was nearly always from the **right section** on a neighbouring page,
e.g. South Asia Outlook p. 96 above p. 98, or Box 1.1 p. 32 above p. 30. The
one exception is g20, where a Chapter 1 summary chunk came second. Neighbour
expansion widens each hit with the chunks either side of it in its section,
so the expected page still reached the LLM, and every answer was correct and
cited.
