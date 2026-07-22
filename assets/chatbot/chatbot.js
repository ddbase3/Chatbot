(function() {

        function initChatbot(rootSel = '#chatbot', config = {}) {
                const root = document.querySelector(rootSel);
                if (!root || root.dataset.inited === '1') return;
                root.dataset.inited = '1';

                const $root = $(root);
                const chatControl = $root.find('.chat');
                const msgControl = $root.find('textarea[name=prompt]');
                const btnSend = $root.find('#chatSend');
                const basePrompt = $root.find('.baseprompt');
                const threadsHost = $root.find('[name=chatthreads]');

                // Canvas elements
                const canvasElem = $root.find('.chatbot-canvas');
                const canvasTitleElem = $root.find('.chatbot-canvas .canvas-title');
                const canvasContentElem = $root.find('.chatbot-canvas .canvas-content');
                const canvasCloseBtn = $root.find('.chatbot-canvas .canvas-close');

                // Suggestions container (bottom-right, styling via CSS)
                const suggestionsContainer = $('<div class="chat-suggestions" aria-live="polite"></div>');
                $root.find('.chatbot-main').append(suggestionsContainer);

                let pendingInteraction = null;

                // ---------------------------------------------------------------------
                // Utility
                // ---------------------------------------------------------------------

                function scrollToBottom() {
                        chatControl.stop().animate({ scrollTop: chatControl[0].scrollHeight }, 300);
                }

                function scrollToResponse() {
                        const last = chatControl.children().last();
                        const offset = last.offset().top - chatControl.offset().top + chatControl.scrollTop();
                        chatControl.stop().animate({ scrollTop: offset - 10 }, 300);
                }

                function cleanForVoice(str) {
                        let txt = str.replace(/<[^>]*>/g, ' ');
                        txt = txt
                                .replace(/(\*\*|__)(.*?)\1/g, '$2')
                                .replace(/(\*|_)(.*?)\1/g, '$2')
                                .replace(/`([^`]*)`/g, '$1')
                                .replace(/```[\s\S]*?```/g, '')
                                .replace(/#+\s?(.*)/g, '$1')
                                .replace(/\[(.*?)\]\(.*?\)/g, '$1')
                                .replace(/!\[(.*?)\]\(.*?\)/g, '$1')
                                .replace(/[\p{Emoji_Presentation}\p{Extended_Pictographic}]/gu, '')
                                .replace(/\s+/g, ' ')
                                .trim();
                        return txt;
                }

                function escapeHtml(str) {
                        return $('<div>').text(str || '').html();
                }

                function parseEventPayload(data) {
                        if (typeof data !== 'string') {
                                return data;
                        }

                        try {
                                return JSON.parse(data);
                        } catch {
                                return data;
                        }
                }

                function normalizeInteractionPayload(payload) {
                        payload = parseEventPayload(payload);

                        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                                return null;
                        }

                        const resumeHandle = String(payload.resume_handle || '').trim();
                        const requests = Array.isArray(payload.interaction_requests)
                                ? payload.interaction_requests.filter(request => request && typeof request === 'object')
                                : [];

                        if (!resumeHandle || !requests.length) {
                                return null;
                        }

                        return {
                                status: String(payload.status || ''),
                                resume_handle: resumeHandle,
                                interaction_requests: requests
                        };
                }

                function formatInteractionValue(value) {
                        if (value === null || value === undefined || value === '') {
                                return '-';
                        }
                        if (typeof value === 'boolean') {
                                return value ? 'Ja' : 'Nein';
                        }
                        if (typeof value === 'object') {
                                try {
                                        return JSON.stringify(value);
                                } catch {
                                        return String(value);
                                }
                        }
                        return String(value);
                }

                function renderInteractionRequired(targetElem, payload, onDecision) {
                        const interaction = normalizeInteractionPayload(payload);
                        if (!interaction) return false;

                        targetElem.empty();
                        const container = $('<div class="agent-interaction-required" role="group" aria-label="Bestätigung erforderlich"></div>');

                        interaction.interaction_requests.forEach(request => {
                                const card = $('<div class="agent-interaction-card"></div>');
                                const header = $('<div class="agent-interaction-header"></div>');
                                const title = $('<strong class="agent-interaction-title"></strong>').text(
                                        String(request.title || 'Bestätigung erforderlich')
                                );
                                const risk = String(request.risk || '').trim();
                                header.append(title);
                                if (risk) {
                                        header.append($('<span class="agent-interaction-risk"></span>').text(risk));
                                }
                                card.append(header);

                                const message = String(request.message || '').trim();
                                if (message) {
                                        card.append($('<div class="agent-interaction-message"></div>').text(message));
                                }

                                const summary = request.summary && typeof request.summary === 'object' && !Array.isArray(request.summary)
                                        ? request.summary
                                        : null;
                                if (summary && Object.keys(summary).length) {
                                        const summaryGrid = $('<dl class="agent-interaction-summary"></dl>');
                                        Object.entries(summary).forEach(([label, value]) => {
                                                summaryGrid.append($('<dt></dt>').text(String(label)));
                                                summaryGrid.append($('<dd></dd>').text(formatInteractionValue(value)));
                                        });
                                        card.append(summaryGrid);
                                }

                                const action = request.action && typeof request.action === 'object' ? request.action : null;
                                if (action) {
                                        const technical = {
                                                tool: String(action.name || ''),
                                                input: action.input && typeof action.input === 'object' ? action.input : {}
                                        };
                                        const details = $('<details class="agent-interaction-details"><summary>Technische Details</summary><pre></pre></details>');
                                        details.find('pre').text(JSON.stringify(technical, null, 2));
                                        card.append(details);
                                }

                                container.append(card);
                        });

                        container.append($('<div class="agent-interaction-hint"></div>').text(
                                'Antworte in deiner nächsten Nachricht frei formuliert mit Zustimmung, Ablehnung oder den angeforderten Angaben.'
                        ));

                        const approvalOnly = interaction.interaction_requests.every(request => String(request.kind || '') === 'approval');
                        if (approvalOnly && typeof onDecision === 'function') {
                                const actions = $('<div class="agent-interaction-actions"></div>');
                                const approve = $('<button type="button" class="agent-interaction-approve">Zustimmen</button>');
                                const deny = $('<button type="button" class="agent-interaction-deny">Ablehnen</button>');
                                approve.on('click', () => {
                                        actions.find('button').prop('disabled', true);
                                        onDecision('Ich stimme zu.');
                                });
                                deny.on('click', () => {
                                        actions.find('button').prop('disabled', true);
                                        onDecision('Ich lehne ab.');
                                });
                                actions.append(approve, deny);
                                container.append(actions);
                        }

                        targetElem.append(container);
                        return true;
                }

                function getGlobalValue(path) {
                        if (!path || typeof path !== 'string') return null;

                        const parts = path.split('.');
                        let value = window;

                        for (const part of parts) {
                                if (!part || value == null || (typeof value !== 'object' && typeof value !== 'function')) {
                                        return null;
                                }

                                value = value[part];
                        }

                        return value;
                }

                function normalizeReference(reference) {
                        if (!reference || typeof reference !== 'object' || Array.isArray(reference)) {
                                return null;
                        }

                        return {
                                ...reference,
                                sentAt: new Date().toISOString()
                        };
                }

                function getDefaultReference() {
                        return normalizeReference({
                                type: 'page',
                                url: window.location.href,
                                title: document.title || '',
                                referrer: document.referrer || ''
                        });
                }

                function getReferencePayload() {
                        const mode = String(config.referenceMode || 'url').toLowerCase();

                        if (mode === 'none') {
                                return null;
                        }

                        if (mode === 'custom') {
                                return normalizeReference(config.reference || null);
                        }

                        if (mode === 'provider') {
                                const providerName = String(config.referenceProvider || '').trim();
                                const provider = getGlobalValue(providerName);

                                if (typeof provider !== 'function') {
                                        return null;
                                }

                                return normalizeReference(provider({
                                        root,
                                        config
                                }));
                        }

                        return getDefaultReference();
                }

                function encodeBase64Url(str) {
                        const bytes = new TextEncoder().encode(str);
                        let binary = '';

                        for (const byte of bytes) {
                                binary += String.fromCharCode(byte);
                        }

                        return btoa(binary)
                                .replace(/\+/g, '-')
                                .replace(/\//g, '_')
                                .replace(/=+$/g, '');
                }

                function appendReference(data) {
                        const reference = getReferencePayload();

                        if (reference) {
                                data.reference = encodeBase64Url(JSON.stringify(reference));
                                data.reference_format = 'base64json';
                        }

                        return data;
                }

                function patchLinksTargetBlank(scope) {
                        const $scope = scope && scope.jquery ? scope : $(scope);
                        if (!$scope || !$scope.length) return;

                        $scope.find('a[href]').each(function() {
                                const $a = $(this);
                                const href = String($a.attr('href') || '').trim();

                                if (!href || href.startsWith('#') || href.toLowerCase().startsWith('javascript:')) {
                                        return;
                                }

                                $a.attr('target', '_blank');

                                const relRaw = String($a.attr('rel') || '');
                                const rel = relRaw.split(/\s+/).filter(Boolean);

                                if (!rel.includes('noopener')) rel.push('noopener');
                                if (!rel.includes('noreferrer')) rel.push('noreferrer');

                                $a.attr('rel', rel.join(' '));
                        });
                }

                function renderMarkdownOrText(targetElem, text) {
                        if (config.useMarkdown && window.marked) {
                                targetElem.html(marked.parse(text));
                                patchLinksTargetBlank(targetElem);
                                return;
                        }

                        targetElem.text(text);
                }

                function toolArgsPreview(toolName, args, maxLen = 110) {
                        let s = '';

                        if (args) {
                                const pick =
                                        args.query || args.q || args.text || args.prompt ||
                                        args.question || args.input || args.search || args.term;

                                if (typeof pick === 'string' && pick.trim()) {
                                        s = pick.trim();
                                } else if (Array.isArray(args.messages)) {
                                        const lastUser = [...args.messages].reverse().find(m => m && m.role === 'user' && typeof m.content === 'string');
                                        if (lastUser) s = lastUser.content.trim();
                                } else if (Array.isArray(args.documents) && args.documents.length) {
                                        s = `${args.documents.length} document(s)`;
                                }
                        }

                        if (!s) {
                                try {
                                        const flat = Object.entries(args || {})
                                                .map(([k, v]) => {
                                                        if (v == null) return '';
                                                        if (typeof v === 'string') return v.trim() ? `${k}: ${v.trim()}` : '';
                                                        if (typeof v === 'number' || typeof v === 'boolean') return `${k}: ${v}`;
                                                        if (Array.isArray(v)) return `${k}: [${v.length}]`;
                                                        return `${k}: …`;
                                                })
                                                .filter(Boolean)
                                                .join(' · ');
                                        s = flat || '';
                                } catch {
                                        s = '';
                                }
                        }

                        s = s.replace(/\s+/g, ' ').trim();
                        if (!s) return '';

                        if (s.length > maxLen) s = s.slice(0, maxLen - 1) + '…';
                        return s;
                }

                function isNearBottom(threshold = 40) {
                        const el = chatControl[0];
                        return (el.scrollHeight - (el.scrollTop + el.clientHeight)) < threshold;
                }

                // ---------------------------------------------------------------------
                // Canvas Controller (minimal)
                // ---------------------------------------------------------------------

                const canvasState = {
                        id: 'main',
                        isOpen: false
                };

                function canvasSetOpen(open) {
                        canvasState.isOpen = !!open;
                        if (canvasState.isOpen) {
                                root.classList.add('canvas-open');
                                canvasElem.attr('aria-hidden', 'false');
                        } else {
                                root.classList.remove('canvas-open');
                                canvasElem.attr('aria-hidden', 'true');
                        }
                }

                function canvasOpen(payload = {}) {
                        payload = parseEventPayload(payload);

                        const id = (payload && payload.id) ? String(payload.id) : 'main';
                        canvasState.id = id;

                        const title = payload && payload.title ? String(payload.title) : 'Canvas';
                        canvasTitleElem.text(title);

                        canvasSetOpen(true);
                }

                function canvasClose(payload = {}) {
                        payload = parseEventPayload(payload);

                        const id = payload && payload.id ? String(payload.id) : null;
                        if (id && id !== canvasState.id) {
                                return;
                        }
                        canvasSetOpen(false);
                }

                function renderCanvasBlocks(blocks, mode = 'replace') {
                        if (!Array.isArray(blocks)) blocks = [];

                        if (mode === 'replace') {
                                canvasContentElem.empty();
                        }

                        for (const block of blocks) {
                                if (!block || typeof block !== 'object') continue;

                                const type = (block.type || '').toLowerCase();

                                // HTML block
                                if (type === 'html') {
                                        const html = String(block.html || '');
                                        const wrap = $('<div class="canvas-block canvas-block-html"></div>');
                                        wrap.html(html);
                                        patchLinksTargetBlank(wrap);
                                        canvasContentElem.append(wrap);
                                        continue;
                                }

                                // Markdown block
                                if (type === 'markdown') {
                                        const md = String(block.markdown || '');
                                        const wrap = $('<div class="canvas-block canvas-block-markdown"></div>');
                                        renderMarkdownOrText(wrap, md);
                                        canvasContentElem.append(wrap);
                                        continue;
                                }

                                // Tool block (canvas-internal widgets) - placeholder for later
                                if (type === 'tool') {
                                        const toolName = String(block.tool || 'tool');
                                        const wrap = $(`
                                                <div class="canvas-block canvas-block-tool">
                                                        <div class="canvas-tool-title">Canvas Tool: ${escapeHtml(toolName)}</div>
                                                        <pre class="canvas-tool-params"></pre>
                                                </div>
                                        `);
                                        try {
                                                wrap.find('.canvas-tool-params').text(JSON.stringify(block.params || {}, null, 2));
                                        } catch {
                                                wrap.find('.canvas-tool-params').text('{}');
                                        }
                                        canvasContentElem.append(wrap);
                                        continue;
                                }

                                // Unknown block
                                const wrap = $('<div class="canvas-block canvas-block-unknown"></div>');
                                wrap.text('Unknown canvas block.');
                                canvasContentElem.append(wrap);
                        }
                }

                function canvasRender(payload = {}) {
                        payload = parseEventPayload(payload);

                        const id = payload && payload.id ? String(payload.id) : 'main';
                        canvasState.id = id;

                        if (payload && payload.title) {
                                canvasTitleElem.text(String(payload.title));
                        }

                        const mode = payload && payload.mode ? String(payload.mode) : 'replace';
                        const blocks = payload && Array.isArray(payload.blocks) ? payload.blocks : [];

                        canvasSetOpen(true);
                        renderCanvasBlocks(blocks, mode);
                }

                // Local close button
                canvasCloseBtn.off('click.chatbotCanvas').on('click.chatbotCanvas', e => {
                        e.preventDefault();
                        canvasClose({ id: canvasState.id });
                });

                // ---------------------------------------------------------------------
                // Base Prompt
                // ---------------------------------------------------------------------

                $.get(config.serviceUrl, appendReference({ baseprompt: 1 }), res => {
                        basePrompt.html(res);
                        patchLinksTargetBlank(basePrompt);
                });

                // ---------------------------------------------------------------------
                // Icon Bar – Copy + Like + Dislike (with toggle)
                // ---------------------------------------------------------------------

                function renderIconBar(toolsElem, fullText, msgId) {
                        if (!config.useIcons || !toolsElem) return;

                        const icons = config.icons || {};

                        let likeBtn = $(
                                `<a title="helpful" href="#" class="like-btn"><img src="${icons.thumbsup}"></a>`
                        );
                        let dislikeBtn = $(
                                `<a title="not helpful" href="#" class="dislike-btn"><img src="${icons.thumbsdown}"></a>`
                        );
                        let copyBtn = $(
                                `<a title="copy" href="#" class="copy-btn"><img src="${icons.copy}"></a>`
                        );

                        toolsElem.append(copyBtn, likeBtn, dislikeBtn);

                        // Copy
                        copyBtn.on('click', function(e) {
                                e.preventDefault();
                                navigator.clipboard.writeText(fullText).then(() => {
                                        const img = $(this).find('img');
                                        img.attr('src', icons.check);
                                        setTimeout(() => img.attr('src', icons.copy), 1000);
                                });
                        });

                        // Like / Dislike
                        const parentMsg = toolsElem.closest('.message.assistent');

                        if (!parentMsg.attr('data-feedback')) {
                                parentMsg.attr('data-feedback', 'none');
                        }

                        function updateVisual() {
                                const state = parentMsg.attr('data-feedback');

                                if (state === 'like') {
                                        likeBtn.find('img').attr('src', icons.thumbsupfill);
                                        dislikeBtn.hide();
                                } else if (state === 'dislike') {
                                        dislikeBtn.find('img').attr('src', icons.thumbsdownfill);
                                        likeBtn.hide();
                                } else {
                                        likeBtn.find('img').attr('src', icons.thumbsup);
                                        dislikeBtn.find('img').attr('src', icons.thumbsdown);
                                        likeBtn.show();
                                        dislikeBtn.show();
                                }
                        }

                        function sendFeedback(type) {
                                if (!msgId) return;

                                $.post(
                                        config.serviceUrl,
                                        { feedback: type, messageid: msgId },
                                        function(res) { /* optional */ },
                                        'json'
                                );
                        }

                        likeBtn.on('click', function(e) {
                                e.preventDefault();
                                const s = parentMsg.attr('data-feedback');

                                if (s === 'like') {
                                        parentMsg.attr('data-feedback', 'none');
                                        sendFeedback('like');
                                } else {
                                        parentMsg.attr('data-feedback', 'like');
                                        sendFeedback('like');
                                }

                                updateVisual();
                        });

                        dislikeBtn.on('click', function(e) {
                                e.preventDefault();
                                const s = parentMsg.attr('data-feedback');

                                if (s === 'dislike') {
                                        parentMsg.attr('data-feedback', 'none');
                                        sendFeedback('dislike');
                                } else {
                                        parentMsg.attr('data-feedback', 'dislike');
                                        sendFeedback('dislike');
                                }

                                updateVisual();
                        });

                        updateVisual();
                }

                // ---------------------------------------------------------------------
                // Prompt Suggestions
                // ---------------------------------------------------------------------

                function renderSuggestions(list) {
                        suggestionsContainer.removeClass('loading');
                        suggestionsContainer.empty();

                        if (!Array.isArray(list) || !list.length) {
                                suggestionsContainer.removeClass('has-suggestions');
                                return;
                        }

                        suggestionsContainer.addClass('has-suggestions');

                        const max = 3;
                        for (let i = 0; i < list.length && i < max; i++) {
                                let text = list[i];
                                if (typeof text !== 'string') continue;
                                text = text.trim();
                                if (!text) continue;

                                const btn = $('<button type="button" class="chat-suggestion"></button>');
                                btn.text(text);

                                btn.on('click', function(e) {
                                        e.preventDefault();
                                        const current = msgControl.val() || '';
                                        if (!current.trim()) {
                                                msgControl.val(text);
                                        } else {
                                                msgControl.val(current.replace(/\s*$/, ' ') + text);
                                        }
                                        msgControl.focus();
                                        msgControl.trigger('input');
                                });

                                btn.on('dblclick', function(e) {
                                        e.preventDefault();
                                        const current = msgControl.val() || '';
                                        if (!current.trim()) {
                                                msgControl.val(text);
                                        }
                                        msgControl.focus();
                                        msgControl.trigger('input');
                                        sendMessage();
                                });

                                suggestionsContainer.append(btn);
                        }
                }

                function fetchSuggestions(lastMsgId) {
                        if (!config.serviceUrl) return;

                        const data = appendReference({ suggestions: 1 });
                        if (lastMsgId) {
                                data.after = lastMsgId;
                        }

                        suggestionsContainer.addClass('loading');
                        suggestionsContainer.empty();

                        $.getJSON(config.serviceUrl, data)
                                .done(res => {
                                        renderSuggestions(res);
                                })
                                .fail(() => {
                                        suggestionsContainer.removeClass('loading');
                                        suggestionsContainer.removeClass('has-suggestions');
                                        suggestionsContainer.empty();
                                });
                }

                // ---------------------------------------------------------------------
                // Main Chat Send
                // ---------------------------------------------------------------------

                async function sendMessage() {
                        const raw = msgControl.val() || '';
                        const plain = raw.trim();
                        if (!plain) return;

                        const resumeContext = pendingInteraction && pendingInteraction.resume_handle
                                ? { ...pendingInteraction }
                                : null;
                        const buildRequestPayload = base => {
                                const data = {
                                        ...base,
                                        config_group: String(config.configGroup || ''),
                                        config_name: String(config.configName || '')
                                };
                                if (resumeContext) {
                                        data.resume_handle = resumeContext.resume_handle;
                                        data.resume_response = plain;
                                }
                                return appendReference(data);
                        };

                        // Clear current suggestions while new answer is generated
                        suggestionsContainer.removeClass('has-suggestions loading');
                        suggestionsContainer.empty();

                        chatControl.removeClass('chatempty');
                        basePrompt.remove();
                        root.classList.add('chatstarted');

                        // User message
                        const userHtml = raw.replace(/\n/g, '<br>');
                        msgControl.val('');
                        chatControl.append('<div class="message user">' + userHtml + '</div>');
                        scrollToBottom();

                        // Assistant message container
                        const respElem = $('<div class="message assistent"></div>').appendTo(chatControl);
                        const contentElem = $('<div class="assistant-content"></div>').appendTo(respElem);

                        // Initial "thinking" indicator
                        const thinkingElem = $(`
                                <div class="assistant-thinking" aria-live="polite">
                                        <span class="dots" aria-hidden="true"><i></i><i></i><i></i></span>
                                </div>
                        `).appendTo(contentElem);

                        function hideThinking() {
                                thinkingElem.remove();
                        }

                        let toolsElem = null;
                        if (config.useIcons) {
                                toolsElem = $('<div class="chat-tools"></div>').appendTo(respElem);
                        }

                        let fullText = '';
                        let renderTimeout = null;
                        let currentMessageId = null;
                        let interactionRendered = false;

                        // Reset per-message tool counter
                        respElem.attr('data-toolcount', '0');

                        function scheduleRender() {
                                if (renderTimeout) return;

                                renderTimeout = setTimeout(() => {
                                        renderMarkdownOrText(contentElem, fullText);
                                        renderTimeout = null;
                                        scrollToBottom();
                                }, 60);
                        }

                        // -----------------------------------------------------------------
                        // REST MODE
                        // -----------------------------------------------------------------

                        if (config.transportMode === 'rest') {
                                const loader = $('<div class="loading"><div class="spinner"></div></div>');
                                chatControl.append(loader);
                                scrollToBottom();

                                try {
                                        const response = await fetch(config.serviceUrl, {
                                                method: 'POST',
                                                headers: {
                                                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                                },
                                                body: new URLSearchParams(buildRequestPayload({
                                                        prompt: plain,
                                                        transport_mode: 'rest'
                                                }))
                                        });

                                        if (!response.ok) {
                                                throw new Error('HTTP ' + response.status);
                                        }

                                        const json = await response.json();

                                        currentMessageId = json.id || ('msg_' + Date.now());
                                        respElem.attr('data-msgid', currentMessageId);

                                        hideThinking();
                                        if (json && json.type === 'interaction_required') {
                                                const interaction = normalizeInteractionPayload(json);
                                                if (!interaction) {
                                                        throw new Error('Invalid interaction_required response.');
                                                }
                                                pendingInteraction = interaction;
                                                interactionRendered = renderInteractionRequired(contentElem, interaction, text => {
                                                        msgControl.val(text).trigger('input');
                                                        sendMessage();
                                                });
                                        } else {
                                                pendingInteraction = null;
                                                fullText = json.text || '';
                                                renderMarkdownOrText(contentElem, fullText);
                                        }
                                } catch (err) {
                                        console.error('REST request failed:', err);
                                        hideThinking();
                                        contentElem.append('<div class="error">Fehler bei der Serveranfrage.</div>');
                                } finally {
                                        loader.remove();
                                        scrollToBottom();

                                        if (!interactionRendered) {
                                                renderIconBar(toolsElem, fullText, currentMessageId);
                                        }

                                        if (config.useVoice && root._voiceCtrl) {
                                                const txt = cleanForVoice(contentElem.text());
                                                root._voiceCtrl.handleAssistantReply(txt);
                                        }

                                        // After a completed assistant answer: fetch prompt suggestions.
                                        if (!interactionRendered) fetchSuggestions(currentMessageId);
                                }

                                return;
                        }

                        // -----------------------------------------------------------------
                        // STREAMING MODE
                        // -----------------------------------------------------------------

                        const client = new ChatbotStreamClient({
                                prepareUrl: config.turnPrepareUrl,
                                service: config.serviceId,
                                events: [
                                        'msgid', 'token', 'done', 'error',
                                        'tool.started', 'tool.finished', 'tool.error',
                                        'stage.started', 'stage.finished', 'stage.error',
                                        'canvas.open', 'canvas.close', 'canvas.render',
                                        'agent.interaction.required'
                                ],
                                payload: buildRequestPayload({
                                        prompt: plain,
                                        transport_mode: 'sse'
                                })
                        });

                        let finished = false;
                        let activityOutputPhase = false;
                        let turnIdElem = null;
                        const stageActivityRows = new Map();
                        const toolActivityRows = new Map();

                        const ensureActivityShell = () => {
                                let shell = respElem.children('.agent-activity-shell').first();

                                if (shell.length) return shell;

                                shell = $(`
                                        <div class="agent-activity-shell">
                                                <button type="button" class="agent-activity-toggle" aria-expanded="true" hidden>
                                                        <span class="agent-activity-toggle-label">Arbeitsschritte ausblenden</span>
                                                        <span class="agent-activity-toggle-count"></span>
                                                </button>
                                                <div class="agent-activity-log" aria-label="Agent activity" aria-live="polite"></div>
                                        </div>
                                `);
                                shell.find('.agent-activity-toggle').on('click', () => {
                                        const isCollapsed = shell.hasClass('is-collapsed');
                                        setActivityCollapsed(!isCollapsed);
                                });
                                shell.insertBefore(contentElem);
                                return shell;
                        };

                        const ensureActivityLog = () => ensureActivityShell().children('.agent-activity-log').first();

                        const updateActivityToggle = () => {
                                const shell = ensureActivityShell();
                                const count = ensureActivityLog().children('.agent-activity-entry:not(.agent-turn-id)').length;
                                const button = shell.children('.agent-activity-toggle');
                                const collapsed = shell.hasClass('is-collapsed');

                                button.prop('hidden', !activityOutputPhase || count === 0);
                                button.find('.agent-activity-toggle-label').text(
                                        collapsed ? 'Arbeitsschritte anzeigen' : 'Arbeitsschritte ausblenden'
                                );
                                button.find('.agent-activity-toggle-count').text(count > 0 ? '(' + count + ')' : '');
                                button.attr('aria-expanded', collapsed ? 'false' : 'true');
                        };

                        const setActivityCollapsed = collapsed => {
                                const shell = ensureActivityShell();
                                shell.toggleClass('is-collapsed', Boolean(collapsed));
                                updateActivityToggle();
                        };

                        const beginOutputPhase = () => {
                                if (activityOutputPhase) return;

                                activityOutputPhase = true;
                                const count = ensureActivityLog().children('.agent-activity-entry:not(.agent-turn-id)').length;
                                if (count > 0) {
                                        setActivityCollapsed(true);
                                } else {
                                        updateActivityToggle();
                                }
                        };

                        const scrollActivityToBottom = () => {
                                const shell = ensureActivityShell();
                                const elem = ensureActivityLog();
                                const node = elem[0];

                                updateActivityToggle();
                                if (!node || shell.hasClass('is-collapsed')) return;

                                requestAnimationFrame(() => {
                                        node.scrollTop = node.scrollHeight;
                                        scrollToBottom();
                                });
                        };

                        const renderTurnId = turnId => {
                                turnId = String(turnId || '').trim();
                                if (!turnId) return;

                                if (!turnIdElem || !turnIdElem.length) {
                                        turnIdElem = $(`
                                                <div class="agent-activity-entry agent-stage-activity agent-turn-id" data-status="completed">
                                                        <span class="agent-activity-icon" aria-hidden="true">#</span>
                                                        <span class="agent-activity-label">Turn ID</span>
                                                        <span class="agent-activity-description"></span>
                                                </div>
                                        `);
                                }

                                turnIdElem.find('.agent-activity-description').text(turnId);
                                ensureActivityLog().prepend(turnIdElem);
                                updateActivityToggle();
                        };

                        const stageActivityKey = payload => {
                                const iteration = Number(payload.iteration || 0);
                                const id = String(payload.id || payload.name || 'stage');
                                return iteration + ':' + id;
                        };

                        const ensureStageActivity = payload => {
                                const key = stageActivityKey(payload);
                                let elem = stageActivityRows.get(key);

                                if (elem && elem.length) return elem;

                                elem = $(`
                                        <div class="agent-activity-entry agent-stage-activity" data-status="running">
                                                <span class="agent-activity-icon" aria-hidden="true">⚙</span>
                                                <span class="agent-activity-label"></span>
                                                <span class="agent-activity-description"></span>
                                                <span class="agent-activity-meta"></span>
                                                <span class="agent-activity-state" aria-hidden="true"></span>
                                        </div>
                                `);
                                stageActivityRows.set(key, elem);
                                ensureActivityLog().append(elem);
                                return elem;
                        };

                        const formatActivityNumber = value => {
                                const number = Number(value);
                                if (!Number.isFinite(number)) return '?';
                                return Math.round(number).toLocaleString('de-DE');
                        };

                        const budgetActivityMeta = payload => {
                                const resultMetadata = (payload.result_metadata && typeof payload.result_metadata === 'object')
                                        ? payload.result_metadata
                                        : {};
                                const assessment = (resultMetadata.budget && typeof resultMetadata.budget === 'object')
                                        ? resultMetadata.budget
                                        : null;

                                if (!assessment) return [];

                                const budget = (assessment.budget && typeof assessment.budget === 'object') ? assessment.budget : {};
                                const usage = (assessment.usage && typeof assessment.usage === 'object') ? assessment.usage : {};
                                const stageMeta = (assessment.metadata && typeof assessment.metadata === 'object') ? assessment.metadata : {};
                                const parts = [];
                                const configuredLimits = [
                                        budget.max_input_tokens, budget.max_output_tokens, budget.max_total_tokens,
                                        budget.max_ai_operations, budget.max_tool_calls, budget.max_elapsed_ms
                                ].some(value => value !== null && value !== undefined);
                                const metricLimits = (budget.metric_limits && typeof budget.metric_limits === 'object')
                                        ? Object.keys(budget.metric_limits).length
                                        : 0;

                                if (!configuredLimits && metricLimits === 0) {
                                        parts.push('budget unlimited');
                                        return parts;
                                }

                                if (budget.max_total_tokens !== null && budget.max_total_tokens !== undefined) {
                                        parts.push('tokens ' + formatActivityNumber(usage.total_tokens) + '/' + formatActivityNumber(budget.max_total_tokens));
                                }

                                if (budget.max_ai_operations !== null && budget.max_ai_operations !== undefined) {
                                        parts.push('AI ops ' + formatActivityNumber(assessment.ai_operation_count) + '/' + formatActivityNumber(budget.max_ai_operations));
                                }

                                if (budget.max_tool_calls !== null && budget.max_tool_calls !== undefined) {
                                        const projected = stageMeta.projected_tool_call_count ?? assessment.tool_call_count;
                                        parts.push('tools ' + formatActivityNumber(projected) + '/' + formatActivityNumber(budget.max_tool_calls));
                                }

                                if (budget.max_elapsed_ms !== null && budget.max_elapsed_ms !== undefined) {
                                        parts.push('time ' + (Number(assessment.elapsed_ms || 0) / 1000).toFixed(1) + 's/' + (Number(budget.max_elapsed_ms) / 1000).toFixed(1) + 's');
                                }

                                const exceeded = (assessment.exceeded_limits && typeof assessment.exceeded_limits === 'object')
                                        ? Object.keys(assessment.exceeded_limits)
                                        : [];
                                const unknown = (assessment.unknown_limits && typeof assessment.unknown_limits === 'object')
                                        ? Object.keys(assessment.unknown_limits)
                                        : [];

                                if (exceeded.length) parts.push('exceeded: ' + exceeded.join(', '));
                                if (unknown.length) parts.push('unknown: ' + unknown.join(', '));

                                return parts;
                        };

                        const continuationActivityMeta = payload => {
                                const resultMetadata = (payload.result_metadata && typeof payload.result_metadata === 'object')
                                        ? payload.result_metadata
                                        : {};
                                const continuation = (resultMetadata.continuation && typeof resultMetadata.continuation === 'object')
                                        ? resultMetadata.continuation
                                        : null;

                                if (!continuation) return [];

                                const parts = [];
                                if (continuation.decision) parts.push(String(continuation.decision));

                                if (continuation.confidence !== null && continuation.confidence !== undefined && continuation.confidence !== '') {
                                        const confidence = Number(continuation.confidence);
                                        if (Number.isFinite(confidence)) {
                                                parts.push('confidence ' + Math.round(confidence * 100) + '%');
                                        }
                                }

                                return parts;
                        };

                        const semanticVerificationActivityMeta = payload => {
                                const resultMetadata = (payload.result_metadata && typeof payload.result_metadata === 'object')
                                        ? payload.result_metadata
                                        : {};
                                const verification = (resultMetadata.semantic_verification && typeof resultMetadata.semantic_verification === 'object')
                                        ? resultMetadata.semantic_verification
                                        : null;

                                if (!verification) return [];

                                const parts = [];
                                if (verification.verdict) parts.push(String(verification.verdict));

                                const metadata = (verification.metadata && typeof verification.metadata === 'object')
                                        ? verification.metadata
                                        : {};
                                if (metadata.recommendation) parts.push(String(metadata.recommendation));

                                if (metadata.confidence !== null && metadata.confidence !== undefined && metadata.confidence !== '') {
                                        const confidence = Number(metadata.confidence);
                                        if (Number.isFinite(confidence)) {
                                                parts.push('confidence ' + Math.round(confidence * 100) + '%');
                                        }
                                }

                                if (resultMetadata.parse_status && resultMetadata.parse_status !== 'valid') {
                                        parts.push('parse ' + String(resultMetadata.parse_status));
                                }

                                return parts;
                        };


                        const toolCacheActivityMeta = payload => {
                                const resultMetadata = (payload.result_metadata && typeof payload.result_metadata === 'object')
                                        ? payload.result_metadata
                                        : {};
                                const cache = (resultMetadata.tool_cache && typeof resultMetadata.tool_cache === 'object')
                                        ? resultMetadata.tool_cache
                                        : null;

                                if (!cache) return [];

                                const parts = [];
                                const names = ['hits', 'misses', 'bypassed', 'stored', 'skipped', 'errors'];

                                names.forEach(name => {
                                        const value = Number(cache[name]);
                                        if (Number.isFinite(value) && value > 0) {
                                                parts.push(name + ' ' + Math.round(value));
                                        }
                                });

                                if (!parts.length) parts.push('no cache changes');
                                return parts;
                        };

                        const progressActivityMeta = payload => {
                                const resultMetadata = (payload.result_metadata && typeof payload.result_metadata === 'object')
                                        ? payload.result_metadata
                                        : {};
                                const progress = (resultMetadata.progress && typeof resultMetadata.progress === 'object')
                                        ? resultMetadata.progress
                                        : null;

                                if (!progress) return [];

                                const parts = [];
                                if (progress.verdict) parts.push(String(progress.verdict));

                                const stalled = Number(progress.consecutive_stalled_iterations);
                                if (Number.isFinite(stalled) && stalled > 0) {
                                        parts.push('stalled ' + Math.round(stalled));
                                }

                                const metadata = (progress.metadata && typeof progress.metadata === 'object')
                                        ? progress.metadata
                                        : {};
                                if (metadata.terminated === true) parts.push('tool phase ended');

                                return parts;
                        };

                        const outputActivityMeta = payload => {
                                const resultMetadata = (payload.result_metadata && typeof payload.result_metadata === 'object')
                                        ? payload.result_metadata
                                        : {};
                                const output = (resultMetadata.output && typeof resultMetadata.output === 'object')
                                        ? resultMetadata.output
                                        : null;

                                if (!output) return [];

                                const parts = [];
                                if (output.source) parts.push('source ' + String(output.source));
                                if (output.warning) parts.push('warning ' + String(output.warning));
                                return parts;
                        };

                        const renderStageActivity = (payload, status) => {
                                const elem = ensureStageActivity(payload);
                                const aiUsage = String(payload.ai_usage || 'none');
                                const name = String(payload.name || payload.id || 'stage');
                                const description = String(payload.description || '');
                                const duration = Number(payload.duration_ms);
                                const iteration = Number(payload.iteration || 0);
                                const usesAi = aiUsage === 'required' || aiUsage === 'conditional';
                                const meta = [];

                                if (iteration > 0) meta.push('loop ' + iteration);
                                if (aiUsage === 'required') meta.push('AI');
                                if (aiUsage === 'conditional') meta.push('AI if needed');
                                if (aiUsage === 'none') meta.push('no AI');
                                if (Number.isFinite(duration)) meta.push(Math.round(duration) + ' ms');
                                meta.push(...budgetActivityMeta(payload));
                                meta.push(...semanticVerificationActivityMeta(payload));
                                meta.push(...continuationActivityMeta(payload));
                                meta.push(...toolCacheActivityMeta(payload));
                                meta.push(...progressActivityMeta(payload));
                                meta.push(...outputActivityMeta(payload));

                                elem.attr('data-status', status || 'running');
                                elem.find('.agent-activity-icon').text(usesAi ? '🧠' : '⚙');
                                elem.find('.agent-activity-label').text(name);
                                elem.find('.agent-activity-description').text(description);
                                elem.find('.agent-activity-meta').text(meta.join(' · '));
                                scrollActivityToBottom();
                        };

                        const toolActivityKey = payload => {
                                const callId = String(payload.call_id || '').trim();
                                if (callId) return callId;

                                return [
                                        String(payload.iteration || 0),
                                        String(payload.call_index || 0),
                                        String(payload.tool || payload.label || 'tool')
                                ].join(':');
                        };

                        const ensureToolActivity = payload => {
                                const key = toolActivityKey(payload);
                                let elem = toolActivityRows.get(key);

                                if (elem && elem.length) return elem;

                                elem = $(`
                                        <div class="agent-activity-entry tool-event" data-status="running">
                                                <div class="agent-activity-line">
                                                        <span class="agent-activity-icon" aria-hidden="true">🔧</span>
                                                        <span class="agent-activity-label"></span>
                                                        <span class="agent-activity-description"></span>
                                                        <span class="agent-activity-meta"></span>
                                                        <span class="agent-activity-state" aria-hidden="true"></span>
                                                </div>
                                                <details class="tool-params">
                                                        <summary>params</summary>
                                                        <pre></pre>
                                                </details>
                                        </div>
                                `);
                                elem.find('details.tool-params').on('toggle', event => {
                                        const detailsNode = event.currentTarget;

                                        if (!detailsNode || !detailsNode.open) return;

                                        requestAnimationFrame(() => {
                                                const logNode = ensureActivityLog()[0];

                                                if (!logNode) return;

                                                const logRect = logNode.getBoundingClientRect();
                                                const detailsRect = detailsNode.getBoundingClientRect();

                                                if (detailsRect.bottom > logRect.bottom) {
                                                        logNode.scrollTop += detailsRect.bottom - logRect.bottom + 4;
                                                }

                                                if (detailsRect.top < logRect.top) {
                                                        logNode.scrollTop -= logRect.top - detailsRect.top + 4;
                                                }
                                        });
                                });

                                toolActivityRows.set(key, elem);
                                ensureActivityLog().append(elem);
                                return elem;
                        };

                        const renderToolActivity = (payload, status) => {
                                const elem = ensureToolActivity(payload);
                                const toolName = String(payload.label || payload.tool || 'tool');
                                const args = (payload.args && typeof payload.args === 'object') ? payload.args : {};
                                const preview = toolArgsPreview(toolName, args);
                                const iteration = Number(payload.iteration || 0);
                                const callIndex = Number(payload.call_index || 0);
                                const meta = [];

                                if (iteration > 0) meta.push('loop ' + iteration);
                                if (callIndex > 0) meta.push('#' + callIndex);
                                if (status === 'completed') meta.push(payload.cached ? 'cached' : 'done');
                                if (status === 'failed') meta.push('failed');

                                elem.attr('data-status', status || 'running');
                                elem.find('.agent-activity-label').text(toolName);
                                elem.find('.agent-activity-description').text(preview ? '“' + preview + '”' : 'tool call');
                                elem.find('.agent-activity-meta').text(meta.join(' · '));
                                elem.find('pre').text(JSON.stringify(args, null, 2)
                                        .replace(/\n/g, '\n')
                                        .replace(/ {4}/g, '  '));

                                if (status === 'failed' && payload.error) {
                                        elem.find('.agent-activity-description').text(String(payload.error));
                                }

                                scrollActivityToBottom();
                        };

                        await client.connect((event, data) => {

                                // -----------------------------
                                // AGENT STAGE ACTIVITY
                                // -----------------------------
                                if (event === 'stage.started') {
                                        hideThinking();
                                        data = parseEventPayload(data);
                                        renderStageActivity((data && typeof data === 'object') ? data : {}, 'running');
                                        return;
                                }

                                if (event === 'stage.finished') {
                                        data = parseEventPayload(data);
                                        const payload = (data && typeof data === 'object') ? data : {};
                                        renderStageActivity(payload, payload.status === 'failed' ? 'failed' : 'completed');
                                        return;
                                }

                                if (event === 'stage.error') {
                                        data = parseEventPayload(data);
                                        renderStageActivity((data && typeof data === 'object') ? data : {}, 'failed');
                                        return;
                                }

                                // -----------------------------
                                // CANVAS EVENTS
                                // -----------------------------
                                if (event === 'canvas.open') {
                                        canvasOpen(data);
                                        return;
                                }

                                if (event === 'canvas.close') {
                                        canvasClose(data);
                                        return;
                                }

                                if (event === 'canvas.render') {
                                        canvasRender(data);
                                        return;
                                }

                                // -----------------------------
                                // MSGID
                                // -----------------------------
                                if (event === 'msgid') {
                                        data = parseEventPayload(data);

                                        if (data && typeof data === 'object') {
                                                currentMessageId = data.id || data.msgid || ('msg_' + Date.now());
                                        } else {
                                                currentMessageId = 'msg_' + Date.now();
                                        }

                                        respElem.attr('data-msgid', currentMessageId);
                                        renderTurnId(currentMessageId);
                                        return;
                                }

                                // -----------------------------
                                // TOOL ACTIVITY
                                // -----------------------------
                                if (event === 'tool.started') {
                                        hideThinking();
                                        data = parseEventPayload(data);
                                        renderToolActivity((data && typeof data === 'object') ? data : {}, 'running');
                                        return;
                                }

                                if (event === 'tool.finished') {
                                        data = parseEventPayload(data);
                                        renderToolActivity((data && typeof data === 'object') ? data : {}, 'completed');
                                        return;
                                }

                                if (event === 'tool.error') {
                                        data = parseEventPayload(data);
                                        renderToolActivity((data && typeof data === 'object') ? data : {}, 'failed');
                                        return;
                                }

                                // -----------------------------
                                // AGENT INTERACTION
                                // -----------------------------
                                if (event === 'agent.interaction.required') {
                                        beginOutputPhase();
                                        hideThinking();
                                        const interaction = normalizeInteractionPayload(data);
                                        if (!interaction) return;

                                        pendingInteraction = interaction;
                                        interactionRendered = renderInteractionRequired(contentElem, interaction, text => {
                                                msgControl.val(text).trigger('input');
                                                sendMessage();
                                        });
                                        scrollToResponse();
                                        return;
                                }

                                // -----------------------------
                                // TOKEN (Phase 2)
                                // -----------------------------
                                if (event === 'token') {
                                        pendingInteraction = null;
                                        beginOutputPhase();
                                        hideThinking();

                                        let tokenText = '';

                                        if (typeof data === 'string') {
                                                tokenText = data;
                                        } else if (data && typeof data === 'object') {
                                                tokenText = String(data.text ?? data.token ?? data.content ?? '');
                                        }

                                        if (tokenText !== '') {
                                                fullText += tokenText;
                                                scheduleRender();
                                        }

                                        return;
                                }

                                // -----------------------------
                                // DONE
                                // -----------------------------
                                if (event === 'done') {
                                        if (finished) return;
                                        finished = true;

                                        beginOutputPhase();
                                        hideThinking();
                                        if (!currentMessageId) {
                                                currentMessageId = 'msg_' + Date.now();
                                                respElem.attr('data-msgid', currentMessageId);
                                        }

                                        if (!interactionRendered) {
                                                pendingInteraction = null;
                                                if (fullText.trim() === '') {
                                                        fullText = 'Es konnte keine sichtbare Antwort erzeugt werden. Bitte versuche die Anfrage erneut.';
                                                }

                                                renderMarkdownOrText(contentElem, fullText);
                                                renderIconBar(toolsElem, fullText, currentMessageId);
                                        }
                                        scrollToResponse();

                                        if (config.useVoice && root._voiceCtrl) {
                                                const txt = cleanForVoice(contentElem.text());
                                                root._voiceCtrl.handleAssistantReply(txt);
                                        }

                                        client.close();

                                        // After a completed assistant answer: fetch prompt suggestions.
                                        if (!interactionRendered) fetchSuggestions(currentMessageId);
                                        return;
                                }

                                // -----------------------------
                                // ERROR
                                // -----------------------------
                                if (event === 'error') {
                                        hideThinking();
                                        console.error('Streaming error event:', data);
                                        client.close();
                                }
                        });
                }

                // ---------------------------------------------------------------------
                // Input Events
                // ---------------------------------------------------------------------

                btnSend.off('click.chatbot').on('click.chatbot', e => {
                        e.preventDefault();
                        sendMessage();
                });

                msgControl.off('keydown.chatbot').on('keydown.chatbot', e => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                sendMessage();
                        }
                });

                // Auto-resize
                msgControl.off('input.chatbot').on('input.chatbot', function() {
                        this.style.height = 'auto';
                        const newHeight = Math.min(this.scrollHeight, 150);
                        this.style.height = Math.max(newHeight, 50) + 'px';
                }).trigger('input');

                // ---------------------------------------------------------------------
                // Threads Control
                // ---------------------------------------------------------------------

                if (config.useThreads && typeof ChatThreadsControl !== 'undefined' && threadsHost.length) {
                        const threadsCtrl = new ChatThreadsControl({
                                events: {
                                        onListRequested: () => {},
                                        onNewThreadRequested: () => {}
                                }
                        });

                        threadsCtrl.attachTo(threadsHost[0]);
                        root._threadsCtrl = threadsCtrl;
                }

                // ---------------------------------------------------------------------
                // Voice Control
                // ---------------------------------------------------------------------

                if (config.useVoice) {
                        const voiceCtrl = new ChatVoiceControl({
                                stt: 'browser',
                                tts: 'browser',
                                lang: config.defaultLang || 'auto',
                                availableLangs: [
                                        { code: 'auto', label: 'Auto' },
                                        { code: 'de-DE', label: 'Deutsch' },
                                        { code: 'en-US', label: 'English' },
                                        { code: 'fr-FR', label: 'Français' },
                                        { code: 'es-ES', label: 'Español' },
                                        { code: 'it-IT', label: 'Italiano' },
                                        { code: 'pt-PT', label: 'Português' },
                                        { code: 'bg-BG', label: 'Български' },
                                        { code: 'ro-RO', label: 'Română' },
                                        { code: 'uk-UA', label: 'Українська' },
                                        { code: 'ru-RU', label: 'Русский' }
                                ],
                                events: {
                                        onUserFinishedSpeaking: text => {
                                                const cur = msgControl.val();
                                                msgControl.val(cur ? cur + ' ' + text : text);
                                        },
                                        onSendRequested: () => sendMessage()
                                }
                        });

                        voiceCtrl.attachTo($root.find('[name=chatvoice]')[0]);
                        root._voiceCtrl = voiceCtrl;
                }
        }

        window.initChatbot = initChatbot;

})();
