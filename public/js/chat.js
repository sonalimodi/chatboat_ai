(function () {
    'use strict';

    var ICONS = {
        avatarUser: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        avatarAssistant: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><line x1="12" y1="7" x2="12" y2="11"/><line x1="8" y1="16" x2="8" y2="16"/><line x1="16" y1="16" x2="16" y2="16"/></svg>',
        copy: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        regenerate: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        file: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
    };

    var appEl = document.querySelector('.app');
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var newChatUrl = appEl.dataset.newChatUrl;
    var chatIndexUrl = appEl.dataset.chatIndexUrl;
    var currentUuid = appEl.dataset.activeUuid || null;

    var messagesEl = document.getElementById('messages');
    var loadingRowEl = document.getElementById('loading-row');
    var errorEl = document.getElementById('error-banner');
    var errorTextEl = document.getElementById('error-banner-text');
    var retryBtn = document.getElementById('retry-btn');
    var formEl = document.getElementById('chat-form');
    var inputEl = document.getElementById('message-input');
    var sendBtn = document.getElementById('send-btn');
    var newChatBtn = document.getElementById('new-chat-btn');
    var headerNewChatBtn = document.getElementById('header-new-chat-btn');
    var sessionListEl = document.getElementById('session-list');
    var chatTitleEl = document.querySelector('.chat-title');

    var sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
    var sidebarCloseBtn = document.getElementById('sidebar-close-btn');
    var sidebarBackdrop = document.getElementById('sidebar-backdrop');

    var themeToggleBtn = document.getElementById('theme-toggle-btn');

    var modelSelector = document.getElementById('model-selector');
    var modelSelectorBtn = document.getElementById('model-selector-btn');
    var modelSelectorLabel = document.getElementById('model-selector-label');
    var modelDropdown = document.getElementById('model-dropdown');

    var attachBtn = document.getElementById('attach-btn');
    var attachmentInput = document.getElementById('attachment-input');
    var pendingAttachmentEl = document.getElementById('pending-attachment');
    var pendingAttachmentNameEl = document.getElementById('pending-attachment-name');
    var removeAttachmentBtn = document.getElementById('remove-attachment-btn');

    var THEME_KEY = 'chatbot_theme';
    var MODEL_KEY = 'chatbot_selected_model';
    var MAX_ATTACHMENT_KB = parseInt(appEl.dataset.maxAttachmentKb, 10) || 5120;

    var availableModels = [];
    var selectedModelKey = null;
    var pendingAttachment = null;

    // ---------------------------------------------------------------
    // URLs
    // ---------------------------------------------------------------

    function messageEndpoint(uuid) {
        return chatIndexUrl.replace(/\/$/, '') + '/' + uuid + '/messages';
    }

    function regenerateEndpoint(uuid) {
        return chatIndexUrl.replace(/\/$/, '') + '/' + uuid + '/regenerate';
    }

    function sessionShowUrl(uuid) {
        return chatIndexUrl.replace(/\/$/, '') + '/' + uuid;
    }

    function sessionDeleteEndpoint(uuid) {
        return chatIndexUrl.replace(/\/$/, '') + '/' + uuid;
    }

    function jsonHeaders() {
        return {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
            'Content-Type': 'application/json',
        };
    }

    // ---------------------------------------------------------------
    // Theme
    // ---------------------------------------------------------------

    function getStoredTheme() {
        try {
            return window.localStorage.getItem(THEME_KEY);
        } catch (e) {
            return null;
        }
    }

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try {
            window.localStorage.setItem(THEME_KEY, theme);
        } catch (e) { /* ignore - private mode / storage disabled */ }
    }

    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
    }

    themeToggleBtn.addEventListener('click', function () {
        setTheme(currentTheme() === 'light' ? 'dark' : 'light');
    });

    if (!getStoredTheme()) {
        setTheme('dark');
    }

    // ---------------------------------------------------------------
    // Mobile sidebar drawer
    // ---------------------------------------------------------------

    function openSidebar() {
        appEl.classList.add('sidebar-open');
    }

    function closeSidebar() {
        appEl.classList.remove('sidebar-open');
    }

    sidebarToggleBtn.addEventListener('click', openSidebar);
    sidebarCloseBtn.addEventListener('click', closeSidebar);
    sidebarBackdrop.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeSidebar();
            closeModelDropdown();
        }
    });

    // ---------------------------------------------------------------
    // Model selector
    // ---------------------------------------------------------------

    function loadAvailableModels() {
        var el = document.getElementById('available-models');
        if (!el) {
            return [];
        }
        try {
            var parsed = JSON.parse(el.textContent || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function getStoredModelKey() {
        try {
            return window.localStorage.getItem(MODEL_KEY);
        } catch (e) {
            return null;
        }
    }

    function storeModelKey(key) {
        try {
            window.localStorage.setItem(MODEL_KEY, key);
        } catch (e) { /* ignore */ }
    }

    function findModel(key) {
        for (var i = 0; i < availableModels.length; i++) {
            if (availableModels[i].key === key) {
                return availableModels[i];
            }
        }
        return null;
    }

    function renderModelSelector() {
        if (!availableModels.length) {
            modelSelectorLabel.textContent = 'No AI provider configured';
            modelSelectorBtn.disabled = true;
            modelDropdown.innerHTML = '<div class="model-dropdown-empty">No AI provider is currently enabled. Check your .env configuration.</div>';
            return;
        }

        var stored = getStoredModelKey();
        selectedModelKey = findModel(stored) ? stored : availableModels[0].key;
        storeModelKey(selectedModelKey);

        var active = findModel(selectedModelKey);
        modelSelectorLabel.textContent = active.label;

        modelDropdown.innerHTML = '';
        availableModels.forEach(function (model) {
            var opt = document.createElement('button');
            opt.type = 'button';
            opt.className = 'model-option' + (model.key === selectedModelKey ? ' active' : '');
            opt.setAttribute('role', 'option');
            opt.dataset.key = model.key;

            var top = document.createElement('div');
            top.className = 'model-option-top';

            var nameRow = document.createElement('div');
            nameRow.className = 'model-option-name-row';

            var name = document.createElement('span');
            name.className = 'model-option-name';
            name.textContent = model.label;
            nameRow.appendChild(name);

            if (model.key === selectedModelKey) {
                var check = document.createElement('span');
                check.className = 'model-option-check';
                check.innerHTML = ICONS.check;
                nameRow.appendChild(check);
            }

            top.appendChild(nameRow);

            if (model.badge) {
                var badge = document.createElement('span');
                badge.className = 'model-option-badge';
                badge.textContent = model.badge;
                top.appendChild(badge);
            }

            var desc = document.createElement('div');
            desc.className = 'model-option-desc';
            desc.textContent = model.description;

            opt.appendChild(top);
            opt.appendChild(desc);

            opt.addEventListener('click', function () {
                selectedModelKey = model.key;
                storeModelKey(selectedModelKey);
                renderModelSelector();
                closeModelDropdown();
            });

            modelDropdown.appendChild(opt);
        });
    }

    function openModelDropdown() {
        if (modelSelectorBtn.disabled) {
            return;
        }
        modelDropdown.hidden = false;
        modelSelector.classList.add('open');
        modelSelectorBtn.setAttribute('aria-expanded', 'true');
    }

    function closeModelDropdown() {
        modelDropdown.hidden = true;
        modelSelector.classList.remove('open');
        modelSelectorBtn.setAttribute('aria-expanded', 'false');
    }

    modelSelectorBtn.addEventListener('click', function () {
        if (modelDropdown.hidden) {
            openModelDropdown();
        } else {
            closeModelDropdown();
        }
    });

    document.addEventListener('click', function (e) {
        if (!modelSelector.contains(e.target)) {
            closeModelDropdown();
        }
    });

    availableModels = loadAvailableModels();
    renderModelSelector();

    // ---------------------------------------------------------------
    // Attachments (PDF / TXT)
    // ---------------------------------------------------------------

    function formatFileSize(bytes) {
        if (bytes >= 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function showPendingAttachment(file) {
        pendingAttachmentNameEl.textContent = file.name + ' (' + formatFileSize(file.size) + ')';
        pendingAttachmentEl.hidden = false;
    }

    function clearPendingAttachment() {
        pendingAttachment = null;
        attachmentInput.value = '';
        pendingAttachmentEl.hidden = true;
        pendingAttachmentNameEl.textContent = '';
    }

    attachBtn.addEventListener('click', function () {
        attachmentInput.click();
    });

    attachmentInput.addEventListener('change', function () {
        var file = attachmentInput.files && attachmentInput.files[0];
        if (!file) {
            return;
        }

        var isAllowedType = /\.(pdf|txt)$/i.test(file.name);
        if (!isAllowedType) {
            showError('Only PDF or TXT files can be attached.', false);
            attachmentInput.value = '';
            return;
        }

        if (file.size > MAX_ATTACHMENT_KB * 1024) {
            showError('That file is too large - the limit is ' + formatFileSize(MAX_ATTACHMENT_KB * 1024) + '.', false);
            attachmentInput.value = '';
            return;
        }

        hideError();
        pendingAttachment = file;
        showPendingAttachment(file);
        inputEl.focus();
    });

    removeAttachmentBtn.addEventListener('click', clearPendingAttachment);

    // ---------------------------------------------------------------
    // Markdown rendering (marked + DOMPurify), with a safe plain-text
    // fallback if either library fails to load.
    // ---------------------------------------------------------------

    function configureMarkdownRenderer() {
        if (!window.marked || !window.DOMPurify) {
            return;
        }

        window.marked.setOptions({ breaks: true, gfm: true });

        window.DOMPurify.addHook('afterSanitizeAttributes', function (node) {
            if (node.tagName === 'A') {
                node.setAttribute('target', '_blank');
                node.setAttribute('rel', 'noopener noreferrer');
            }
        });
    }

    function renderAssistantBubble(bubbleEl, text) {
        bubbleEl.textContent = text;

        if (!window.marked || !window.DOMPurify) {
            return;
        }

        try {
            var html = window.marked.parse(text);
            bubbleEl.innerHTML = window.DOMPurify.sanitize(html);
            bubbleEl.classList.add('markdown-rendered');
        } catch (e) {
            // Keep the plain-text fallback already set above.
        }
    }

    // ---------------------------------------------------------------
    // Message rendering
    // ---------------------------------------------------------------

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function clearEmptyState() {
        var empty = document.getElementById('empty-state');
        if (empty) {
            empty.remove();
        }
    }

    function formatTime(isoOrDate) {
        var date = isoOrDate instanceof Date ? isoOrDate : new Date(isoOrDate);
        if (isNaN(date.getTime())) {
            return '';
        }
        return date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function refreshRegenerateButtons() {
        var assistantMessages = messagesEl.querySelectorAll('.message-assistant');
        assistantMessages.forEach(function (el, index) {
            var btn = el.querySelector('.regenerate-btn');
            if (!btn) {
                return;
            }
            btn.hidden = index !== assistantMessages.length - 1;
        });
    }

    function appendMessage(role, text, options) {
        options = options || {};
        clearEmptyState();

        var wrapper = document.createElement('div');
        wrapper.className = 'message message-' + role;

        var avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.innerHTML = role === 'user' ? ICONS.avatarUser : ICONS.avatarAssistant;

        var content = document.createElement('div');
        content.className = 'message-content';

        var meta = document.createElement('div');
        meta.className = 'message-meta';

        var roleName = document.createElement('span');
        roleName.className = 'message-role-name';
        roleName.textContent = role === 'user' ? 'You' : 'Assistant';
        meta.appendChild(roleName);

        if (options.providerLabel) {
            var badge = document.createElement('span');
            badge.className = 'message-provider-badge';
            badge.textContent = options.providerLabel;
            meta.appendChild(badge);
        }

        var time = document.createElement('span');
        time.className = 'message-time';
        time.textContent = formatTime(options.createdAt || new Date());
        meta.appendChild(time);

        content.appendChild(meta);

        if (options.attachmentName) {
            var chip = document.createElement('div');
            chip.className = 'attachment-chip';
            chip.innerHTML = ICONS.file + '<span></span>';
            chip.querySelector('span').textContent = options.attachmentName;
            content.appendChild(chip);
        }

        var bubble = document.createElement('div');
        bubble.className = 'message-bubble';

        if (role === 'assistant') {
            renderAssistantBubble(bubble, text);
        } else {
            bubble.textContent = text;
        }

        content.appendChild(bubble);

        if (role === 'assistant') {
            var actions = document.createElement('div');
            actions.className = 'message-actions';

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'msg-action-btn copy-btn';
            copyBtn.title = 'Copy';
            copyBtn.setAttribute('aria-label', 'Copy message');
            copyBtn.innerHTML = ICONS.copy;
            actions.appendChild(copyBtn);

            var regenBtn = document.createElement('button');
            regenBtn.type = 'button';
            regenBtn.className = 'msg-action-btn regenerate-btn';
            regenBtn.title = 'Regenerate response';
            regenBtn.setAttribute('aria-label', 'Regenerate response');
            regenBtn.innerHTML = ICONS.regenerate;
            regenBtn.hidden = true;
            actions.appendChild(regenBtn);

            content.appendChild(actions);
        }

        wrapper.appendChild(avatar);
        wrapper.appendChild(content);
        messagesEl.appendChild(wrapper);

        if (role === 'assistant') {
            refreshRegenerateButtons();
        }

        scrollToBottom();
        return wrapper;
    }

    // Copy / regenerate via delegated click handling - works for both
    // server-rendered messages (page load) and freshly appended ones.
    messagesEl.addEventListener('click', function (e) {
        var copyBtn = e.target.closest ? e.target.closest('.copy-btn') : null;
        var regenBtn = e.target.closest ? e.target.closest('.regenerate-btn') : null;

        if (copyBtn) {
            var bubble = copyBtn.closest('.message-content').querySelector('.message-bubble');
            var text = bubble ? bubble.textContent : '';

            var markCopied = function () {
                copyBtn.innerHTML = ICONS.check;
                copyBtn.classList.add('copied');
                setTimeout(function () {
                    copyBtn.innerHTML = ICONS.copy;
                    copyBtn.classList.remove('copied');
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(markCopied).catch(function () {});
            }
            return;
        }

        if (regenBtn) {
            regenerateLastReply();
        }
    });

    // ---------------------------------------------------------------
    // Loading / error UI
    // ---------------------------------------------------------------

    function showError(message, allowRetry) {
        errorTextEl.textContent = message;
        errorEl.hidden = false;
        retryBtn.hidden = !allowRetry;
    }

    function hideError() {
        errorEl.hidden = true;
        errorTextEl.textContent = '';
        retryBtn.hidden = true;
    }

    function setLoading(isLoading) {
        loadingRowEl.hidden = !isLoading;
        sendBtn.disabled = isLoading;
        inputEl.disabled = isLoading;
        if (isLoading) {
            scrollToBottom();
        }
    }

    function autoResizeInput() {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 200) + 'px';
    }

    // ---------------------------------------------------------------
    // Sidebar session list
    // ---------------------------------------------------------------

    function markActiveSessionInSidebar(uuid) {
        var items = sessionListEl.querySelectorAll('.session-item');
        items.forEach(function (item) {
            item.classList.toggle('active', item.dataset.uuid === uuid);
        });
    }

    function prependSessionToSidebar(uuid, title) {
        var noSessions = sessionListEl.querySelector('.no-sessions');
        if (noSessions) {
            noSessions.remove();
        }

        var item = document.createElement('div');
        item.className = 'session-item active';
        item.dataset.uuid = uuid;

        var link = document.createElement('a');
        link.className = 'session-link';
        link.href = sessionShowUrl(uuid);
        link.innerHTML = '<svg class="session-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><span class="session-title"></span>';
        link.querySelector('.session-title').textContent = title || 'New conversation';

        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'icon-btn delete-session-btn';
        deleteBtn.dataset.uuid = uuid;
        deleteBtn.title = 'Delete conversation';
        deleteBtn.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

        item.appendChild(link);
        item.appendChild(deleteBtn);
        sessionListEl.insertBefore(item, sessionListEl.firstChild);

        markActiveSessionInSidebar(uuid);
    }

    function updateSessionTitleInSidebar(uuid, title) {
        var item = sessionListEl.querySelector('.session-item[data-uuid="' + uuid + '"]');
        if (item) {
            var titleEl = item.querySelector('.session-title');
            if (titleEl) {
                titleEl.textContent = title || 'New conversation';
            }
        }
        chatTitleEl.textContent = title || 'New conversation';
    }

    function resetToBlankConversation() {
        currentUuid = null;
        messagesEl.innerHTML = '';
        messagesEl.appendChild(buildEmptyState());
        chatTitleEl.textContent = 'New conversation';
        hideError();
        clearPendingAttachment();
        window.history.pushState({}, '', chatIndexUrl);
        markActiveSessionInSidebar(null);
    }

    function buildEmptyState() {
        var wrap = document.createElement('div');
        wrap.className = 'empty-state';
        wrap.id = 'empty-state';
        wrap.innerHTML =
            '<div class="empty-state-inner">' +
            '<h2>How can I help you today?</h2>' +
            '<div class="suggestion-grid">' +
            '<button type="button" class="suggestion-card" data-prompt="Write a function that reverses a string, with an explanation."><span class="suggestion-icon">💻</span><span class="suggestion-text">Write code</span></button>' +
            '<button type="button" class="suggestion-card" data-prompt="Help me debug this: my code throws an error and I don\'t know why. What questions should I answer to figure it out?"><span class="suggestion-icon">🐛</span><span class="suggestion-text">Debug an issue</span></button>' +
            '<button type="button" class="suggestion-card" data-prompt="Help me write a short, friendly email announcing a new feature to my team."><span class="suggestion-icon">✍️</span><span class="suggestion-text">Write something</span></button>' +
            '<button type="button" class="suggestion-card" data-prompt="Brainstorm 5 creative ideas for a weekend side project."><span class="suggestion-icon">💡</span><span class="suggestion-text">Brainstorm ideas</span></button>' +
            '</div></div>';
        return wrap;
    }

    function createNewSession() {
        return fetch(newChatUrl, {
            method: 'POST',
            headers: jsonHeaders(),
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('Unable to create a new conversation.');
            }
            return res.json();
        });
    }

    function startNewChat() {
        hideError();
        clearPendingAttachment();
        createNewSession()
            .then(function (data) {
                currentUuid = data.uuid;
                messagesEl.innerHTML = '';
                messagesEl.appendChild(buildEmptyState());
                chatTitleEl.textContent = 'New conversation';
                window.history.pushState({}, '', data.url);
                prependSessionToSidebar(data.uuid, data.title);
                closeSidebar();
                inputEl.focus();
            })
            .catch(function (err) {
                showError(err.message, false);
            });
    }

    newChatBtn.addEventListener('click', startNewChat);
    headerNewChatBtn.addEventListener('click', startNewChat);

    function onDeleteSessionClick(e) {
        e.preventDefault();
        var uuid = e.currentTarget.dataset.uuid;

        if (!window.confirm('Delete this conversation? This cannot be undone.')) {
            return;
        }

        fetch(sessionDeleteEndpoint(uuid), {
            method: 'DELETE',
            headers: jsonHeaders(),
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('Unable to delete this conversation.');
            }
            var item = sessionListEl.querySelector('.session-item[data-uuid="' + uuid + '"]');
            if (item) {
                item.remove();
            }
            if (currentUuid === uuid) {
                resetToBlankConversation();
            }
            if (!sessionListEl.querySelector('.session-item')) {
                sessionListEl.innerHTML = '<p class="no-sessions">No conversations yet.</p>';
            }
        }).catch(function (err) {
            showError(err.message, false);
        });
    }

    sessionListEl.addEventListener('click', function (e) {
        var deleteBtn = e.target.closest ? e.target.closest('.delete-session-btn') : null;
        if (deleteBtn) {
            onDeleteSessionClick({ preventDefault: function () {}, currentTarget: deleteBtn });
            return;
        }
        if (e.target.closest && e.target.closest('.session-link')) {
            closeSidebar();
        }
    });

    // ---------------------------------------------------------------
    // Suggestion cards (empty state)
    // ---------------------------------------------------------------

    messagesEl.addEventListener('click', function (e) {
        var card = e.target.closest ? e.target.closest('.suggestion-card') : null;
        if (card) {
            inputEl.value = card.dataset.prompt || '';
            autoResizeInput();
            formEl.requestSubmit ? formEl.requestSubmit() : formEl.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    // ---------------------------------------------------------------
    // Sending & regenerating messages
    // ---------------------------------------------------------------

    inputEl.addEventListener('input', autoResizeInput);

    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            formEl.requestSubmit ? formEl.requestSubmit() : formEl.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    });

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        hideError();

        var text = inputEl.value.trim();
        if (!text) {
            return;
        }

        var attachedFile = pendingAttachment;
        var attachmentName = attachedFile ? attachedFile.name : null;

        inputEl.value = '';
        autoResizeInput();
        clearPendingAttachment();

        var ensureSession = currentUuid
            ? Promise.resolve(currentUuid)
            : createNewSession().then(function (data) {
                currentUuid = data.uuid;
                window.history.pushState({}, '', data.url);
                prependSessionToSidebar(data.uuid, data.title);
                return currentUuid;
            });

        appendMessage('user', text, { attachmentName: attachmentName });
        setLoading(true);

        ensureSession
            .then(function (uuid) {
                var body;
                var headers = { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' };

                if (attachedFile) {
                    body = new FormData();
                    body.append('message', text);
                    if (selectedModelKey) {
                        body.append('model', selectedModelKey);
                    }
                    body.append('attachment', attachedFile);
                    // No Content-Type header here - the browser sets the
                    // multipart boundary itself; setting it manually breaks the upload.
                } else {
                    headers['Content-Type'] = 'application/json';
                    body = JSON.stringify({ message: text, model: selectedModelKey });
                }

                return fetch(messageEndpoint(uuid), { method: 'POST', headers: headers, body: body });
            })
            .then(handleReplyResponse)
            .catch(function (err) {
                showError(err.message, !!currentUuid);
            })
            .finally(function () {
                setLoading(false);
                inputEl.focus();
            });
    });

    function regenerateLastReply() {
        if (!currentUuid) {
            return;
        }

        hideError();

        var assistantMessages = messagesEl.querySelectorAll('.message-assistant');
        var lastAssistant = assistantMessages.length ? assistantMessages[assistantMessages.length - 1] : null;
        setLoading(true);

        fetch(regenerateEndpoint(currentUuid), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ model: selectedModelKey }),
        })
            .then(handleReplyResponse)
            .then(function () {
                if (lastAssistant) {
                    lastAssistant.remove();
                }
            })
            .catch(function (err) {
                showError(err.message, true);
            })
            .finally(function () {
                setLoading(false);
            });
    }

    retryBtn.addEventListener('click', regenerateLastReply);

    function handleReplyResponse(res) {
        return res.json().then(function (data) {
            if (!res.ok) {
                var fieldErrors = data.errors ? Object.keys(data.errors).map(function (key) {
                    return data.errors[key].join(' ');
                }).join(' ') : null;
                throw new Error(fieldErrors || data.message || 'Something went wrong. Please try again.');
            }

            var providerLabel = data.provider ? data.provider.label : null;
            appendMessage('assistant', data.assistant_message.message, {
                providerLabel: providerLabel,
                createdAt: data.assistant_message.created_at,
            });
            updateSessionTitleInSidebar(data.session.uuid, data.session.title);

            return data;
        });
    }

    // ---------------------------------------------------------------
    // Init
    // ---------------------------------------------------------------

    configureMarkdownRenderer();

    var initialAssistantBubbles = messagesEl.querySelectorAll('.message-assistant .message-bubble');
    for (var i = 0; i < initialAssistantBubbles.length; i++) {
        var el = initialAssistantBubbles[i];
        renderAssistantBubble(el, el.textContent);
    }
    refreshRegenerateButtons();

    scrollToBottom();
    autoResizeInput();
}());
