<section>
	<div class="frame">
		<div id="chatbot">
			<p class="baseprompt"></p>
			<div class="chat chatempty"></div>
			<form class="chatform">
				<textarea name="prompt"></textarea>
				<div name="chatvoice"></div>
				<input type="submit" name="submit" value="Send" />
			</form>
		</div>
	</div>
</section>

<script src="plugin/Chatbot/assets/chatvoice/chatvoice.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
	const basePrompt = $('#chatbot .baseprompt');
	const chatControl = $('#chatbot .chat');
	const msgControl = $('#chatbot textarea');
	const form = $('#chatbot form');
	const serviceUrl = '<?php echo $this->_['service']; ?>';

	basePrompt.load(serviceUrl + "?baseprompt");

	function scrollToBottom() {
		chatControl.stop().animate({ scrollTop: chatControl[0].scrollHeight }, 300);
	}

	function scrollToResponse() {
		const last = chatControl.children().last();
		const scrollOffset = last.offset().top - chatControl.offset().top + chatControl.scrollTop();
		chatControl.stop().animate({ scrollTop: scrollOffset - 10 }, 300);
	}

	// --- submit handler ---
	form.on('submit', function(e) {
		e.preventDefault();

		chatControl.removeClass('chatempty');
		basePrompt.remove();

		const message = msgControl.val().replace(/(?:\r\n|\r|\n)/g, '<br>');
		msgControl.val('');
		chatControl.append('<div class="message user">' + message + '</div>');
		scrollToBottom();

		const loader = $('<div class="loading"><div class="spinner"></div></div>');
		chatControl.append(loader);
		scrollToBottom();

		$.post(serviceUrl, { prompt: message }, function(result) {
			loader.remove();

			const response = result.replace(/(?:\r\n|\r|\n)/g, '<br>');
			var respElem = $('<div class="message assistent">' + response + '</div>').appendTo(chatControl);
			var toolsElem = $('<div class="chat-tools"></div>').appendTo(respElem);
			toolsElem.append('<a title="copy" href="#"><img src="plugin/Chatbot/assets/icons/copy.svg"></a>');
			toolsElem.append('<a title="helpful" href="#"><img src="plugin/Chatbot/assets/icons/thumbsup.svg"></a>');
			toolsElem.append('<a title="not helpful" href="#"><img src="plugin/Chatbot/assets/icons/thumbsdown.svg"></a>');
			toolsElem.append('<a title="reload" href="#"><img src="plugin/Chatbot/assets/icons/reload.svg"></a>');
			scrollToResponse();

			$('a', toolsElem).on('click', function(e) { e.preventDefault(); });

			// pass reply to voice control
			voiceCtrl.handleAssistantReply(response);
		});
	});

	// --- auto-growing msg control ---
	msgControl
		.on('input', function() {
			this.style.height = 'auto';
			const newHeight = Math.min(this.scrollHeight, 150);
			this.style.height = Math.max(newHeight, 50) + 'px';
		})
		.each(function() {
			this.style.height = 'auto';
			const newHeight = Math.min(this.scrollHeight, 150);
			this.style.height = Math.max(newHeight, 50) + 'px';
		});

	// --- enter-to-submit ---
	msgControl.on('keydown', function(e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			form.submit();
		}
	});

	// --- init voice control ---
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
				console.log("STT finished:", text);
				// text ins textarea übernehmen (Mitschrieb)
				const current = msgControl.val();
				msgControl.val(current ? current + " " + text : text);
			},
			onSendRequested: (text) => {
				console.log("Dialog autosend:", text);
				// im Dialogmodus sofort senden
				form.submit();
			},
			onAssistantReplied: (reply) => console.log("Assistant replied:", reply),
			onTtsStarted: (txt) => console.log("TTS started:", txt),
			onTtsFinished: () => console.log("TTS finished"),
			onRecordingEnded: () => console.log("Recording ended"),
			onError: (err) => console.error("VoiceControl Error:", err)
		}
	});

	// attach voice control UI
	voiceCtrl.attachTo(document.querySelector("#chatbot [name=chatvoice]"));
});
</script>


		<style>
			#chatbot .chat { height:400px; margin:0 0 20px 0; padding:0 10px; overflow-x:hidden; }
			#chatbot .chat.chatempty { height:auto; }
			#chatbot .baseprompt { min-height:40px; margin:100px 0 0; font-size:18pt; text-align:center; }
			#chatbot .chat > .message { margin:10px 0 30px; overflow:auto; }
			#chatbot .chat > .user { max-width:80%; margin-left:auto; padding:10px; border:1px solid #eee; border-radius:5px; background:#f7f7f7; color:#333; }
			#chatbot .chat > .assistent { margin-right:50px; }
			#chatbot .chatform { padding:10px 30px; border:1px solid #ddd; border-radius:30px; }
			#chatbot textarea { width:100%; min-height:50px; max-height:150px; overflow-y:auto; padding:3px 10px; border:0; outline:none; resize:none; }
			#chatbot textarea:focus { border:1px solid #eee; border-radius:5px; background:#f7f7f7; }

			.loading { text-align: center; margin: 10px; }
			.spinner {
				display: inline-block;
				width: 24px; height: 24px;
				border: 3px solid #ccc; border-top: 3px solid #0077cc; border-radius: 50%;
				animation: spin 1s linear infinite;
			}
			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}



			#chatbot [name="chatvoice"] { float: right; width: auto; }
			#chatbot [name="chatvoice"] { text-align: right; }
			#chatbot [name="chatvoice"] button, #chatbot [name="chatvoice"] select { width: auto; vertical-align: middle; margin-left: 4px; }


			#chatbot .chat-tools a { display:inline-block; margin:15px 10px 0; }
			#chatbot .chat-tools img { width:16px; height:16px; opacity:0.5; }


			.info-box {
				border: 2px solid #ccc;
				border-left-width: 6px;
				border-radius: 6px;
				padding: 0.8em 1em;
				margin: 1em 0;
				background-color: #fdfdfd;
				font-family: sans-serif;
				font-size: 0.95em;
				line-height: 1.4;
				box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
			}

			.info-header {
				font-weight: bold;
				font-size: 1em;
				margin-bottom: 0.4em;
			}

			.info-fact   { border-left-color: #0077cc; }
			.info-weather{ border-left-color: #00aaff; }
			.info-joke   { border-left-color: #ffaa00; }
			.info-warning{ border-left-color: #cc0000; }

			.label {
				font-weight: bold;
				display: inline-block;
				width: 90px;
				color: #333;
			}

			.result.richtig { color: #007700; font-weight: bold; }
			.result.falsch  { color: #cc0000; font-weight: bold; }
		</style>
