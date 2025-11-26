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

		// ---------------------------------------------------------------------
		// Base Prompt
		// ---------------------------------------------------------------------
		$.get(config.serviceUrl, { baseprompt: 1 }, res => {
			basePrompt.html(res);
		});

		// ---------------------------------------------------------------------
		// ICON BAR – Copy + Like + Dislike (mit Toggle)
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

			// -------------------------------
			// COPY
			// -------------------------------
			copyBtn.on("click", function(e) {
				e.preventDefault();
				navigator.clipboard.writeText(fullText).then(() => {
					const img = $(this).find("img");
					img.attr("src", icons.check);
					setTimeout(() => img.attr("src", icons.copy), 1000);
				});
			});

			// -------------------------------
			// LIKE / DISLIKE TOGGLE
			// -------------------------------
			const parentMsg = toolsElem.closest(".message.assistent");

			if (!parentMsg.attr("data-feedback")) {
				parentMsg.attr("data-feedback", "none");
			}

			function updateVisual() {
				const state = parentMsg.attr("data-feedback");

				if (state === "like") {
					likeBtn.find("img").attr("src", icons.thumbsupfill);
					dislikeBtn.hide();
				}
				else if (state === "dislike") {
					dislikeBtn.find("img").attr("src", icons.thumbsdownfill);
					likeBtn.hide();
				}
				else {
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
					function(res) { /* optional response */ },
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

			// Assistant Message block
			const respElem = $('<div class="message assistent"></div>').appendTo(chatControl);
			const contentElem = $('<div class="assistant-content"></div>').appendTo(respElem);

			let toolsElem = null;
			if (config.useIcons) {
				toolsElem = $('<div class="chat-tools"></div>').appendTo(respElem);
			}

			let fullText = '';
			let renderTimeout = null;
			let currentMessageId = null;

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
					scheduleRender();

				} catch (err) {
					console.error('REST request failed:', err);
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
				events: ['msgid', 'token', 'done', 'error'],
				payload: { prompt: plain }
			});

			let finished = false;

			await client.connect((event, data) => {

				if (event === 'msgid') {
					if (typeof data === 'string') try { data = JSON.parse(data); } catch {}
					currentMessageId = data.id || data.msgid || ('msg_' + Date.now());
					respElem.attr('data-msgid', currentMessageId);
					return;
				}

				if (event === 'token') {
					if (typeof data === 'string') try { data = JSON.parse(data); } catch {}
					fullText += data.text || '';
					scheduleRender();
					return;
				}

				if (event === 'done') {
					if (finished) return;
					finished = true;

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

				if (event === 'error') {
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
		// VOICE CONTROL
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

