(function() {

	function initChatbot(rootSel = '#chatbot', config = {}) {

		const root = document.querySelector(rootSel);
		if (!root || root.dataset.inited === '1') return;
		root.dataset.inited = '1';

		const $root       = $(root);
		const $basePrompt = $root.find('.baseprompt');
		const $chat       = $root.find('.chat');
		const $textarea   = $root.find('textarea[name=prompt]');
		const $btnSend    = $root.find('#chatSend');

		const serviceUrl = root.getAttribute('data-service');
		if (!serviceUrl) return;

		const useSse = root.dataset.sse === '1';
		const ajaxUrl = serviceUrl.replace(/\.(php|html|json)$/i, '') + '.json';

		// --------------------------------------------------
		// Baseprompt laden
		// --------------------------------------------------
		$.ajax({
			url: ajaxUrl,
			method: 'GET',
			data: { baseprompt: 1 },
			dataType: 'text',
			success: function(result) {
				$basePrompt.html(result);
			}
		});

		// --------------------------------------------------
		// Hilfsfunktionen
		// --------------------------------------------------

		function scrollToBottom() {
			$chat.stop().animate({ scrollTop: $chat[0].scrollHeight }, 200);
		}

		function cleanForVoice(str) {
			return String(str || '')
				.replace(/<[^>]*>/g, ' ')
				.replace(/\s+/g, ' ')
				.trim();
		}

		function buildSseUrl(prompt) {
			const base = serviceUrl.split('?')[0].replace(/\.(php|html|json)$/i, '');
			const name = base.split('/').pop();
			return '/sse/' + name + '?prompt=' + encodeURIComponent(prompt);
		}

		function appendAssistantMessage(text, id) {
			const html = String(text || '').replace(/\n/g, '<br>');
			$('<div class="message assistent"></div>')
				.attr('id', id || '')
				.html(html)
				.appendTo($chat);
			scrollToBottom();
		}

		function showLoader() {
			const $loader = $('<div class="loading"><div class="spinner">...</div></div>');
			$chat.append($loader);
			scrollToBottom();
			return $loader;
		}

		function handleAssistantData(data) {
			if (!data) return;

			const text = data.text ?? '';
			appendAssistantMessage(text, data.id);

			const clean = cleanForVoice(text);
			if (root._voiceCtrl) {
				root._voiceCtrl.handleAssistantReply(clean);
			}
		}

		// --------------------------------------------------
		// Nachricht senden (gemeinsam für AJAX/SSE)
		// --------------------------------------------------

		function sendMessage() {
			const raw  = $textarea.val() || '';
			const text = raw.trim();
			if (!text) return;

			$chat.removeClass('chatempty');
			$root.find('.baseprompt').remove();

			const userHtml = raw.replace(/\n/g, '<br>');
			$textarea.val('');

			$chat.append('<div class="message user">' + userHtml + '</div>');
			scrollToBottom();

			const $loader = showLoader();

			if (useSse) {
				sendMessageSse(text, $loader);
			} else {
				sendMessageAjax(text, $loader);
			}
		}

		// --------------------------------------------------
		// AJAX-Modus (blocking JSON)
		// --------------------------------------------------

		function sendMessageAjax(prompt, $loader) {
			$.ajax({
				url: ajaxUrl,
				method: 'POST',
				dataType: 'json',
				data: { prompt: prompt },
				success: function(data) {
					$loader.remove();
					handleAssistantData(data);
				},
				error: function(_, status, error) {
					$loader.remove();
					appendAssistantMessage('Error: ' + (error || status || 'Unknown error'));
				}
			});
		}

		// --------------------------------------------------
		// SSE-Modus (Streaming)
		// --------------------------------------------------

		function sendMessageSse(prompt, $loader) {
			const url = buildSseUrl(prompt);
			const evt = new EventSource(url);
			let finished = false;

			evt.addEventListener('message', function(e) {
				let data;
				try {
					data = JSON.parse(e.data);
				} catch (_) {
					return;
				}

				// Wir interessieren uns nur für eigentliche Chat-Antworten
				if (data.type !== 'chat_response') {
					return;
				}

				finished = true;
				$loader.remove();
				handleAssistantData(data);
			});

			evt.addEventListener('done', function() {
				finished = true;
				evt.close();
			});

			evt.onerror = function() {
				evt.close();
				if (!finished) {
					$loader.remove();
					appendAssistantMessage('Stream interrupted or failed.');
				}
			};
		}

		// --------------------------------------------------
		// Events/Bindings
		// --------------------------------------------------

		$btnSend.on('click', function(e) {
			e.preventDefault();
			sendMessage();
		});

		$textarea.on('keydown', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});

		// --------------------------------------------------
		// Voice-Control anbinden
		// --------------------------------------------------

		const voiceCtrl = new ChatVoiceControl({
			stt: 'browser',
			tts: 'browser',
			lang: config.defaultLang || 'auto',
			events: {
				onUserFinishedSpeaking: function(text) {
					const current = $textarea.val();
					$textarea.val(current ? current + ' ' + text : text);
				},
				onSendRequested: function() {
					sendMessage();
				},
				onAssistantReplied: function(reply) {
					console.log('Assistant replied:', reply);
				}
			}
		});

		const voiceEl = $root.find('[name=chatvoice]')[0];
		if (voiceEl) {
			voiceCtrl.attachTo(voiceEl);
		}
		root._voiceCtrl = voiceCtrl;
	}

	window.initChatbot = initChatbot;

})();

