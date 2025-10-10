(function() {
	function initChatbot(rootSel = '#chatbot') {
		const root = document.querySelector(rootSel);
		if (!root || root.dataset.inited === '1') return; // prevent double init per node
		root.dataset.inited = '1';

		const $root = $(root);
		const basePrompt = $root.find('.baseprompt');
		const chatControl = $root.find('.chat');
		const msgControl = $root.find('textarea[name=prompt]');
		const btnSend = $root.find('#chatSend');
		const serviceUrl = root.getAttribute('data-service');

		// load base prompt
		$.get(serviceUrl, { baseprompt: 1 }, function(result) {
			basePrompt.html(result);
		});

		function scrollToBottom() {
			chatControl.stop().animate({ scrollTop: chatControl[0].scrollHeight }, 300);
		}
		function scrollToResponse() {
			const last = chatControl.children().last();
			const scrollOffset = last.offset().top - chatControl.offset().top + chatControl.scrollTop();
			chatControl.stop().animate({ scrollTop: scrollOffset - 10 }, 300);
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

		function sendMessage() {
			const raw = msgControl.val() || '';
			const plain = raw.replace(/(?:\r\n|\r|\n)/g, '\n').trim();
			if (!plain) return;

			chatControl.removeClass('chatempty');
			$root.find('.baseprompt').remove();

			const messageHtml = raw.replace(/(?:\r\n|\r|\n)/g, '<br>');
			msgControl.val('');
			chatControl.append('<div class="message user">' + messageHtml + '</div>');
			scrollToBottom();

			const loader = $('<div class="loading"><div class="spinner"></div></div>');
			chatControl.append(loader);
			scrollToBottom();

			$.post(serviceUrl, { prompt: messageHtml }, function(result) {
				loader.remove();

				// parse JSON result
				let data;
				try {
					data = JSON.parse(result);
				} catch (e) {
					// console.error("Invalid JSON response", e, result);
					// return;
					data = { text: result };
				}

				// build assistant message
				const respElem = $('<div class="message assistent"></div>')
					.attr('id', data.id || 0) // visible id for later reference
					.attr('data-markdown', data.markdown || '') // store markdown for copy
					.html(data.html || data.text) // show html output, fallback text
					.appendTo(chatControl);

				// add tools
				const toolsElem = $('<div class="chat-tools"></div>').appendTo(respElem);
				toolsElem.append('<a title="copy" href="#"><img src="plugin/Chatbot/assets/icons/copy.svg"></a>');
				const likeBtn = $('<a title="helpful" href="#"><img src="plugin/Chatbot/assets/icons/thumbsup.svg"></a>').appendTo(toolsElem);
				const dislikeBtn = $('<a title="not helpful" href="#"><img src="plugin/Chatbot/assets/icons/thumbsdown.svg"></a>').appendTo(toolsElem);
				// toolsElem.append('<a title="reload" href="#"><img src="plugin/Chatbot/assets/icons/reload.svg"></a>');
				scrollToResponse();

				// tools click handling
				$('a', toolsElem).on('click', function(e) {
					e.preventDefault();

					const $link = $(this);
					const action = $link.attr('title');

					if (action === 'copy') {
						const markdown = respElem.attr('data-markdown') || '';
						if (!markdown) return;

						navigator.clipboard.writeText(markdown).then(() => {
							const img = $link.find('img');
							const oldSrc = img.attr('src');
							img.attr('src', 'plugin/Chatbot/assets/icons/check.svg');
							setTimeout(() => {
								img.attr('src', oldSrc);
							}, 2000);
						}).catch(err => {
							console.error("Clipboard copy failed:", err);
						});
					}

					if (action === 'helpful') {
						const img = $link.find('img');
						const isActive = img.attr('src').includes('thumbsupfill');

						if (!isActive) {
							// set active
							img.attr('src', 'plugin/Chatbot/assets/icons/thumbsupfill.svg');
							dislikeBtn.hide();
							$.post(serviceUrl, { feedback: 'like', messageid: data.id }, function(res) { console.log(res); });
						} else {
							// remove feedback
							img.attr('src', 'plugin/Chatbot/assets/icons/thumbsup.svg');
							dislikeBtn.show();
							$.post(serviceUrl, { feedback: 'like_removed', messageid: data.id }, function(res) { console.log(res); });
						}
					}

					if (action === 'not helpful') {
						const img = $link.find('img');
						const isActive = img.attr('src').includes('thumbsdownfill');

						if (!isActive) {
							// set active
							img.attr('src', 'plugin/Chatbot/assets/icons/thumbsdownfill.svg');
							likeBtn.hide();
							$.post(serviceUrl, { feedback: 'dislike', messageid: data.id }, function(res) { console.log(res); });
						} else {
							// remove feedback
							img.attr('src', 'plugin/Chatbot/assets/icons/thumbsdown.svg');
							likeBtn.show();
							$.post(serviceUrl, { feedback: 'dislike_removed', messageid: data.id }, function(res) { console.log(res); });
						}
					}

					if (action === 'reload') {
						console.log("Reload clicked for message:", data.id);
						$.post(serviceUrl, { feedback: 'reload', messageid: data.id });
					}
				});

				// prepare text for voice
				const responseForVoice = cleanForVoice(data.text || '');
				root._voiceCtrl && root._voiceCtrl.handleAssistantReply(responseForVoice);
			});
		}

		// button click
		btnSend.off('click.chatbot').on('click.chatbot', function(e) {
			e.preventDefault();
			sendMessage();
		});

		// enter-to-send
		msgControl.off('keydown.chatbot').on('keydown.chatbot', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});

		// auto-growing textarea
		msgControl.off('input.chatbot').on('input.chatbot', function() {
			this.style.height = 'auto';
			const newHeight = Math.min(this.scrollHeight, 150);
			this.style.height = Math.max(newHeight, 50) + 'px';
		}).trigger('input');

		// voice control
		const voiceCtrl = new ChatVoiceControl({
			stt: "browser",
			tts: "browser",
			lang: "auto",
			availableLangs: [
				{ code: "auto", label: "Auto" },
				{ code: "de-DE", label: "Deutsch" },
				{ code: "en-US", label: "English" },
				{ code: "fr-FR", label: "Français" },
				{ code: "es-ES", label: "Español" },
				{ code: "it-IT", label: "Italiano" },
				{ code: "pt-PT", label: "Português" },
				{ code: "bg-BG", label: "Български" },
				{ code: "ro-RO", label: "Română" },
				{ code: "uk-UA", label: "Українська" },
				{ code: "ru-RU", label: "Русский" }
			],
			events: {
				onUserFinishedSpeaking: (text) => {
					const current = msgControl.val();
					msgControl.val(current ? current + " " + text : text);
				},
				onSendRequested: () => sendMessage(),
				onAssistantReplied: (reply) => console.log("Assistant replied:", reply),
				onTtsStarted: (txt) => console.log("TTS started:", txt),
				onTtsFinished: () => console.log("TTS finished"),
				onRecordingEnded: () => console.log("Recording ended"),
				onError: (err) => console.error("VoiceControl Error:", err)
			}
		});
		voiceCtrl.attachTo($root.find('[name=chatvoice]')[0]);
		root._voiceCtrl = voiceCtrl; // store instance per chatbot
	}

	// expose globally
	window.initChatbot = initChatbot;
})();

