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

		// Canvas elements
		const canvasElem = $root.find('.chatbot-canvas');
		const canvasTitleElem = $root.find('.chatbot-canvas .canvas-title');
		const canvasContentElem = $root.find('.chatbot-canvas .canvas-content');
		const canvasCloseBtn = $root.find('.chatbot-canvas .canvas-close');

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
			if (typeof payload === 'string') {
				try { payload = JSON.parse(payload); } catch {}
			}
			const id = (payload && payload.id) ? String(payload.id) : 'main';
			canvasState.id = id;

			const title = payload && payload.title ? String(payload.title) : 'Canvas';
			canvasTitleElem.text(title);

			canvasSetOpen(true);
		}

		function canvasClose(payload = {}) {
			if (typeof payload === 'string') {
				try { payload = JSON.parse(payload); } catch {}
			}
			const id = (payload && payload.id) ? String(payload.id) : null;
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
					// Minimal start: allow tool to provide HTML. "sanitize" flag is reserved for later hardening.
					// For production hardening you can introduce a sanitizer (DOMPurify) or server-side rendering.
					const html = String(block.html || '');
					const wrap = $('<div class="canvas-block canvas-block-html"></div>');
					wrap.html(html);
					canvasContentElem.append(wrap);
					continue;
				}

				// Markdown block
				if (type === 'markdown') {
					const md = String(block.markdown || '');
					const wrap = $('<div class="canvas-block canvas-block-markdown"></div>');
					if (config.useMarkdown && window.marked) {
						wrap.html(marked.parse(md));
					} else {
						wrap.text(md);
					}
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
			if (typeof payload === 'string') {
				try { payload = JSON.parse(payload); } catch {}
			}

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

		// Local close button (pure UI close; assistant can also close via tool-triggered event)
		canvasCloseBtn.off('click.chatbotCanvas').on('click.chatbotCanvas', e => {
			e.preventDefault();
			canvasClose({ id: canvasState.id });
		});

		// ---------------------------------------------------------------------
		// Base Prompt
		// ---------------------------------------------------------------------
		$.get(config.serviceUrl, { baseprompt: 1 }, res => {
			basePrompt.html(res);
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
			copyBtn.on("click", function(e) {
				e.preventDefault();
				navigator.clipboard.writeText(fullText).then(() => {
					const img = $(this).find("img");
					img.attr("src", icons.check);
					setTimeout(() => img.attr("src", icons.copy), 1000);
				});
			});

			// Like / Dislike
			const parentMsg = toolsElem.closest(".message.assistent");

			if (!parentMsg.attr("data-feedback")) {
				parentMsg.attr("data-feedback", "none");
			}

			function updateVisual() {
				const state = parentMsg.attr("data-feedback");

				if (state === "like") {
					likeBtn.find("img").attr("src", icons.thumbsupfill);
					dislikeBtn.hide();
				} else if (state === "dislike") {
					dislikeBtn.find("img").attr("src", icons.thumbsdownfill);
					likeBtn.hide();
				} else {
					likeBtn.find("img").attr("src", icons.thumbsup);
					dislikeBtn.find("img").attr("src", icons.thumbsdown);
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
					"json"
				);
			}

			likeBtn.on("click", function(e) {
				e.preventDefault();
				const s = parentMsg.attr("data-feedback");

				if (s === "like") {
					parentMsg.attr("data-feedback", "none");
					sendFeedback("like");
				} else {
					parentMsg.attr("data-feedback", "like");
					sendFeedback("like");
				}

				updateVisual();
			});

			dislikeBtn.on("click", function(e) {
				e.preventDefault();
				const s = parentMsg.attr("data-feedback");

				if (s === "dislike") {
					parentMsg.attr("data-feedback", "none");
					sendFeedback("dislike");
				} else {
					parentMsg.attr("data-feedback", "dislike");
					sendFeedback("dislike");
				}

				updateVisual();
			});

			updateVisual();
		}

		// ---------------------------------------------------------------------
		// Main Chat Send
		// ---------------------------------------------------------------------

		async function sendMessage() {
			const raw = msgControl.val() || '';
			const plain = raw.trim();
			if (!plain) return;

			chatControl.removeClass('chatempty');
			basePrompt.remove();

			// User message
			const userHtml = raw.replace(/\n/g, '<br>');
			msgControl.val('');
			chatControl.append('<div class="message user">' + userHtml + '</div>');
			scrollToBottom();

			// Assistant message container
			const respElem = $('<div class="message assistent"></div>').appendTo(chatControl);
			const contentElem = $('<div class="assistant-content"></div>').appendTo(respElem);

			// Initial "thinking" indicator (immediate feedback)
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

			// Reset per-message tool counter
			respElem.attr('data-toolcount', '0');

			function scheduleRender() {
				if (!config.useMarkdown) {
					contentElem.text(fullText);
					scrollToBottom();
					return;
				}
				if (renderTimeout) return;
				renderTimeout = setTimeout(() => {
					contentElem.html(marked.parse(fullText));
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
						body: new URLSearchParams({ prompt: plain })
					});

					if (!response.ok) {
						throw new Error('HTTP ' + response.status);
					}

					const json = await response.json();

					currentMessageId = json.id || ('msg_' + Date.now());
					respElem.attr('data-msgid', currentMessageId);

					fullText = json.text || '';
					hideThinking();
					scheduleRender();
				} catch (err) {
					console.error('REST request failed:', err);
					hideThinking();
					contentElem.append('<div class="error">Fehler bei der Serveranfrage.</div>');
				} finally {
					loader.remove();
					scrollToBottom();

					renderIconBar(toolsElem, fullText, currentMessageId);

					if (config.useVoice && root._voiceCtrl) {
						const txt = cleanForVoice(contentElem.text());
						root._voiceCtrl.handleAssistantReply(txt);
					}
				}

				return;
			}

			// -----------------------------------------------------------------
			// STREAMING MODE
			// -----------------------------------------------------------------

			const streamUrl = config.serviceUrl;

			const client = new EventTransportClient({
				endpoint: streamUrl,
				transport: config.transportMode || 'auto',
				events: [
					'msgid', 'token', 'done', 'error',
					'tool.started', 'tool.finished',
					'canvas.open', 'canvas.close', 'canvas.render'
				],
				payload: { prompt: plain }
			});

			let finished = false;

			await client.connect((event, data) => {

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
					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch {}
					}
					currentMessageId = data.id || data.msgid || ('msg_' + Date.now());
					respElem.attr('data-msgid', currentMessageId);
					return;
				}

				// -----------------------------
				// TOOL STARTED (Phase 1)
				// -----------------------------
				if (event === 'tool.started') {
					hideThinking();

					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch {}
					}
					const toolName = data.label || data.tool || 'tool';
					const args = data.args || {};

					const prevCount = parseInt(respElem.attr('data-toolcount') || '0', 10);
					const callIndex = prevCount + 1;
					respElem.attr('data-toolcount', String(callIndex));

					const prettyArgs = JSON.stringify(args, null, 2)
						.replace(/\\n/g, '\n')
						.replace(/ {4}/g, '  ');

					const preview = toolArgsPreview(toolName, args);

					const elem = $(`
						<div class="message tool-event" data-tool="${escapeHtml(toolName)}" data-callindex="${callIndex}">
							<span class="tool-badge">
								<span class="tool-ic" aria-hidden="true">🔧</span>
								<span class="tool-name">${escapeHtml(toolName)}</span>
								${preview ? `<span class="tool-preview">“${escapeHtml(preview)}”</span>` : ``}
								<span class="tool-activity" aria-hidden="true"></span>
								<span class="tool-meta" aria-hidden="true">#${callIndex}</span>
							</span>
							<details class="tool-params">
								<summary>params</summary>
								<pre></pre>
							</details>
						</div>
					`);

					elem.find('pre').text(prettyArgs);

					elem.find('details.tool-params').on('toggle', () => {
						if (!isNearBottom()) return;
						requestAnimationFrame(() => scrollToBottom());
					});

					chatControl.append(elem);
					scrollToBottom();
					return;
				}

				// -----------------------------
				// TOOL FINISHED
				// -----------------------------
				if (false && event === 'tool.finished') { // deactivated, hiding happens too fast
					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch {}
					}
					const toolName = data.tool || 'tool';
					chatControl.find('.tool-event[data-tool="' + toolName + '"]').remove();
					return;
				}

				// -----------------------------
				// TOKEN (Phase 2)
				// -----------------------------
				if (event === 'token') {
					hideThinking();

					// Remove all tool events when token stream starts
					chatControl.find('.tool-event').remove();

					if (typeof data === 'string') {
						try { data = JSON.parse(data); } catch {}
					}
					fullText += data.text || '';
					scheduleRender();
					return;
				}

				// -----------------------------
				// DONE
				// -----------------------------
				if (event === 'done') {
					if (finished) return;
					finished = true;

					hideThinking();

					if (!currentMessageId) {
						currentMessageId = 'msg_' + Date.now();
						respElem.attr('data-msgid', currentMessageId);
					}

					if (config.useMarkdown) {
						contentElem.html(marked.parse(fullText));
					} else {
						contentElem.text(fullText);
					}

					renderIconBar(toolsElem, fullText, currentMessageId);
					scrollToResponse();

					if (config.useVoice && root._voiceCtrl) {
						const txt = cleanForVoice(contentElem.text());
						root._voiceCtrl.handleAssistantReply(txt);
					}

					client.close();
					return;
				}

				// -----------------------------
				// ERROR
				// -----------------------------
				if (event === 'error') {
					hideThinking();
					contentElem.append('<div class="error">Connection error</div>');
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
